# Built-in node handlers

> Архив Notion. Актуальная документация: [[nodes/README]]


Depends on: 11, 12
Domain: Flow
Phase: 2 — Flow Engine
Sprint: 5
Status: Готово
Task №: 13

## Цель

Реализовать шесть встроенных node handlers. Все idempotent. Handler только возвращает результат — engine владеет persist, транзакцией и retry policy.

---

## Ключевые решения

- `NodeExecutionResult` использует `sourceHandle`, не `nextNodeId` — handler объявляет выход, engine резолвит следующую ноду
- При `NodeExecutionStatus::Failed` engine владеет retry policy; после exhausted → активирует `error` output или помечает сессию failed
- `TemplateResolver` — отдельный support-класс, не вшит в `SetAttributeNodeHandler`
- Handler не вызывает `$session->save()`, не открывает `DB::transaction()`, не создаёт scheduled jobs

---

## Файловая структура

```
app/Domains/Flow/
└── Handlers/
    ├── SendMessageNodeHandler.php
    ├── InputNodeHandler.php
    ├── ConditionNodeHandler.php
    ├── DelayNodeHandler.php
    ├── SetAttributeNodeHandler.php
    ├── WebhookNodeHandler.php
    └── Support/
        └── TemplateResolver.php
```

---

## Контракт (из Task 09/11)

```php
final readonly class NodeExecutionResult
{
    public function __construct(
        public NodeExecutionStatus $status,      // Executed | Waiting | Failed
        public ?string $sourceHandle = null,     // 'default', 'yes', 'no', 'timeout', 'error', 'case:vip'
        public array $stateChanges = [],         // что записать в flow_sessions.state
        public array $effects = [],              // declarative side effects — engine обязан интерпретировать
        public array $metadata = [],             // в flow_logs.metadata / resolved
    ) {}
}
```

**effects** — типизированные декларативные эффекты. Handler объявляет намерение, engine исполняет.

Текущие типы эффектов:

| type | payload | кто интерпретирует |
| --- | --- | --- |
| `set_contact_attribute` | `key`, `value` | engine вызывает ContactService |

Handler никогда не вызывает сервис напрямую — только возвращает effect в массиве. Engine исполняет все effects после handler.handle() до persist.

---

## Handlers

### 1. SendMessageNodeHandler — idempotency через sent_message_ids

```php
final class SendMessageNodeHandler extends AbstractVersionedHandler
{
    public function __construct(
        private readonly MessageSenderInterface $sender,
    ) {}

    public function type(): string { return 'send_message'; }
    public function version(): int { return 1; }

    public function handle(NodeExecutionContext $context): NodeExecutionResult
    {
        $nodeId = $context->nodeId;
        $sentIds = $context->session->state()->get('system.sent_messages', []);

        // Idempotency: уже отправляли из этой ноды
        if (isset($sentIds[$nodeId])) {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: 'default',
            );
        }

        $externalMessageId = $this->sender->send(
            channelId: $context->session->channelId(),
            contactExternalId: $context->session->contactExternalId(),
            payload: MessagePayload::fromConfig($context->nodeConfig),
        );

        $sentIds[$nodeId] = $externalMessageId;

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'default',
            stateChanges: ['system.sent_messages' => $sentIds],
            metadata: ['external_message_id' => $externalMessageId],
        );
    }
}
```

### 2. InputNodeHandler — два прохода

```php
final class InputNodeHandler extends AbstractVersionedHandler
{
    public function type(): string { return 'input'; }
    public function version(): int { return 1; }

    public function handle(NodeExecutionContext $context): NodeExecutionResult
    {
        $incomingText = $context->incomingMessage?->text;

        // Первый проход: нет входящего → engine ставит session в waiting_input
        if ($incomingText === null) {
            return new NodeExecutionResult(status: NodeExecutionStatus::Waiting);
        }

        // Второй проход: сообщение есть → сохраняем и идём дальше
        $saveToKey = $context->nodeConfig['save_to'] ?? null; // e.g. "flow.user_name"

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'default',
            stateChanges: $saveToKey ? [$saveToKey => $incomingText] : [],
            metadata: ['received' => $incomingText],
        );
    }
}
```

### 3. ConditionNodeHandler — единственный handler с DataAccessorRegistry

```php
final class ConditionNodeHandler extends AbstractVersionedHandler
{
    public function __construct(
        private readonly DataAccessorRegistryInterface $accessors,
    ) {}

    public function type(): string { return 'condition'; }
    public function version(): int { return 1; }

    public function handle(NodeExecutionContext $context): NodeExecutionResult
    {
        $path = $context->nodeConfig['check']
            ?? throw new InvalidNodeConfigException('condition: missing check');

        $value = str_starts_with($path, 'module.')
            ? $this->accessors->resolve($path, $context->session->contact())
            : $context->session->state()->get($path);

        $handle = $this->evaluateRules($value, $context->nodeConfig['rules'] ?? []);

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: $handle,
            // metadata идёт в flow_logs.resolved — аудит перехода бесплатен
            metadata: [
                'resolved_path'  => $path,
                'resolved_value' => $value,
                'handle'         => $handle,
            ],
        );
    }

    private function evaluateRules(mixed $value, array $rules): string
    {
        foreach ($rules as $rule) {
            if ($this->matchesRule($value, $rule)) {
                return $rule['handle'];
            }
        }
        return 'default';
    }

    private function matchesRule(mixed $value, array $rule): bool
    {
        return match ($rule['operator']) {
            'eq'        => $value == $rule['value'],
            'neq'       => $value != $rule['value'],
            'gt'        => $value > $rule['value'],
            'gte'       => $value >= $rule['value'],
            'lt'        => $value < $rule['value'],
            'lte'       => $value <= $rule['value'],
            'contains'  => str_contains((string) $value, $rule['value']),
            'in'        => in_array($value, $rule['value'], strict: true),
            'empty'     => empty($value),
            'not_empty' => !empty($value),
            default     => false,
        };
    }
}
```

### 4. DelayNodeHandler — idempotency через system.delay.{nodeId}

```php
final class DelayNodeHandler extends AbstractVersionedHandler
{
    public function type(): string { return 'delay'; }
    public function version(): int { return 1; }

    public function handle(NodeExecutionContext $context): NodeExecutionResult
    {
        $alreadyScheduled = $context->session->state()
            ->get("system.delay.{$context->nodeId}.scheduled_at");

        // Idempotency: retry попал сюда снова, job уже создан
        if ($alreadyScheduled !== null) {
            return new NodeExecutionResult(status: NodeExecutionStatus::Waiting);
        }

        $seconds   = (int) ($context->nodeConfig['seconds'] ?? 60);
        $resumeAt  = now()->addSeconds($seconds);

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Waiting,
            stateChanges: [
                "system.delay.{$context->nodeId}.resume_at"    => $resumeAt->toIso8601String(),
                "system.delay.{$context->nodeId}.scheduled_at" => now()->toIso8601String(),
            ],
            metadata: ['resume_at' => $resumeAt->toIso8601String(), 'seconds' => $seconds],
        );
        // Engine при Waiting + delay config создаёт ResumeFlowSessionJob::dispatch()->delay($resumeAt)
        // ResumeFlowSessionJob проверяет current_node_id === expectedNodeId перед запуском
    }
}
```

### 5. SetAttributeNodeHandler — contact или flow.*, шаблоны через TemplateResolver

```php
final class SetAttributeNodeHandler extends AbstractVersionedHandler
{
    public function __construct(
        private readonly TemplateResolver $templates,
    ) {}

    public function type(): string { return 'set_attribute'; }
    public function version(): int { return 1; }

    public function handle(NodeExecutionContext $context): NodeExecutionResult
    {
        $config = $context->nodeConfig;
        $target = $config['target']; // 'contact' | 'flow'
        $key    = $config['key'];
        $value  = $this->templates->resolve($config['value'], $context->session->state());

        // target=flow → stateChanges, engine пишет в flow_sessions.state
        if ($target === 'flow') {
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Executed,
                sourceHandle: 'default',
                stateChanges: ["flow.{$key}" => $value],
                metadata: ['target' => 'flow', 'key' => $key],
            );
        }

        // target=contact → declarative effect, persist делает engine
        // Handler не вызывает ContactService напрямую
        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: 'default',
            effects: [[
                'type'  => 'set_contact_attribute',
                'key'   => $key,
                'value' => $value,
            ]],
            metadata: ['target' => 'contact', 'key' => $key],
        );
    }
}
```

**ContactServiceInterface больше не инжектируется в handler.** Engine интерпретирует effect `set_contact_attribute` и вызывает сервис в рамках своей транзакции.

### 6. WebhookNodeHandler — idempotency key в заголовке

**Граница Failed/Executed:**

- Transport error (timeout, connection refused, DNS fail) → `Failed` — ответ не получен, retry безопасен
- HTTP response получен (любой статус, включая 4xx/5xx) → `Executed` с `sourceHandle: 'success'` или `'error'` — ответ получен, retry небезопасен без idempotency key на стороне получателя

```php
final class WebhookNodeHandler extends AbstractVersionedHandler
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly TemplateResolver $templates,
    ) {}

    public function type(): string { return 'webhook'; }
    public function version(): int { return 1; }

    public function handle(NodeExecutionContext $context): NodeExecutionResult
    {
        $idempotencyKey = "{$context->session->id()}:{$context->nodeId}";
        $config         = $context->nodeConfig;

        $payload = $this->buildPayload($config, $context);

        try {
            $response = $this->http->post(
                url: $config['url'],
                payload: $payload,
                headers: [
                    'X-Idempotency-Key' => $idempotencyKey,
                    'X-FAPost-Session'  => $context->session->id(),
                ],
                timeout: (int) ($config['timeout'] ?? 10),
            );
        } catch (HttpTransportException $e) {
            // Transport error: ответ не получен → Failed, engine управляет retry
            // После exhausted retry → engine активирует 'error' output или session failed
            return new NodeExecutionResult(
                status: NodeExecutionStatus::Failed,
                metadata: [
                    'error'           => $e->getMessage(),
                    'error_type'      => 'transport',
                    'idempotency_key' => $idempotencyKey,
                ],
            );
        }

        // HTTP response получен → Executed независимо от статус кода
        // Retry здесь небезопасен — idempotency key защищает сторону получателя
        $stateChanges = [];
        if ($saveResponseTo = ($config['save_response_to'] ?? null)) {
            $stateChanges[$saveResponseTo] = $response->json();
        }

        return new NodeExecutionResult(
            status: NodeExecutionStatus::Executed,
            sourceHandle: $response->successful() ? 'success' : 'error',
            stateChanges: $stateChanges,
            metadata: [
                'status_code'     => $response->status(),
                'idempotency_key' => $idempotencyKey,
            ],
        );
    }

    private function buildPayload(array $config, NodeExecutionContext $context): array
    {
        $payload = [
            'session_id' => $context->session->id(),
            'contact_id' => $context->session->contact()->id(),
        ];

        foreach ($config['include_state'] ?? [] as $path) {
            $payload['state'][$path] = $context->session->state()->get($path);
        }

        return $payload;
    }
}
```

---

## TemplateResolver

```php
final class TemplateResolver
{
    // Placeholder scope: только flow.* и system.* на данном этапе
    // module.* — расширение в Task 15
    public function resolve(mixed $value, FlowStateInterface $state): mixed
    {
        if (!is_string($value) || !str_contains($value, '{{')) {
            return $value;
        }

        return preg_replace_callback('/\{\{(.+?)\}\}/', function (array $m) use ($state): string {
            return (string) ($state->get(trim($m[1])) ?? '');
        }, $value);
    }
}
```

---

## Регистрация в FlowServiceProvider

```php
public function boot(): void
{
    $registry  = $this->app->make(NodeHandlerRegistryInterface::class);
    $templates = $this->app->make(TemplateResolver::class);

    $registry->register(new SendMessageNodeHandler(
        $this->app->make(MessageSenderInterface::class),
    ));
    $registry->register(new InputNodeHandler());
    $registry->register(new ConditionNodeHandler(
        $this->app->make(DataAccessorRegistryInterface::class),
    ));
    $registry->register(new DelayNodeHandler());
    $registry->register(new SetAttributeNodeHandler(
        $this->app->make(ContactServiceInterface::class),
        $templates,
    ));
    $registry->register(new WebhookNodeHandler(
        $this->app->make(HttpClientInterface::class),
        $templates,
    ));
}
```

---

## What NOT to do (для Cursor)

- Handler не вызывает `$session->save()` — только возвращает `stateChanges`
- Handler не открывает `DB::transaction()` — это engine (Task 11)
- `DelayNodeHandler` не создаёт scheduled job напрямую — только возвращает `Waiting` + stateChanges
- `ConditionNodeHandler` не читает из БД напрямую — только через `DataAccessorRegistryInterface`
- `SendMessageNodeHandler` не бросает исключение при дубле — тихо возвращает `Executed`
- Нет прямых вызовов `Redis::` внутри handlers
- `WebhookNodeHandler` возвращает `Failed` — engine решает retry или error output, handler не знает retry policy
- `TemplateResolver` не расширяется на `module.*` в этой задаче — только `flow.*` и `system.*`

---

## Ожидаемые тесты

- `SendMessageNodeHandler`: повторный вызов с тем же nodeId → `Executed` без вызова sender
- `InputNodeHandler`: первый проход без incomingMessage → `Waiting`; второй с текстом → `Executed` + stateChanges
- `ConditionNodeHandler`: `eq` / `in` / `empty` операторы; `module.*` path → вызов accessor; `flow.*` → из state
- `DelayNodeHandler`: второй вызов с уже записанным `scheduled_at` → `Waiting` без новых stateChanges
- `SetAttributeNodeHandler`: target=contact → вызов contactService; target=flow → stateChanges
- `WebhookNodeHandler`: timeout → `Failed` с metadata; success → `Executed` с sourceHandle='success'
- `TemplateResolver`: `{{flow.name}}` резолвится из state; отсутствующий ключ → пустая строка