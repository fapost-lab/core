# ADR-09 — Message Routing & Concurrency Control

> **Superseded in part (2026-10).** Differences between this ADR and the code:
>
> - The lock heartbeat is **not a timer or background task**. It is a per-node tick inside `FlowEngine::executeLoop`
>   (`refreshSessionLock()` calls `LockHeartbeat::extend()` before each node), so the TTL only has to cover the slowest
>   single node. A failed extend raises `SessionLockLostException` and the run is abandoned.
> - `MessageRouter` has an extra step the ADR does not list: a staff-ownership check (`isHandledByStaff` through
>   `ConversationOwnershipInterface`). A conversation held by an operator is dropped from the flow engine
>   (`staff_handled`) before session state is classified.
> - The typing methods (`indicateProcessing` / `refreshProcessing` / `stopProcessing`) live on the foundation contract
>   `Fapost\Foundation\Messaging\TypingCapableProviderInterface`, not on `ChannelAdapterInterface`. They are driven by
>   `TypingIndicatorService` and `TypingHeartbeatRegistry` in `app/Domains/Messaging/Typing/`.
> - A lock miss is **not** requeued to a backoff queue. `MessageRouter` returns `dropped('lock_timeout')`, and
>   `IncomingMessageJob::shouldRetry` retries only `engine_lock_timeout`.
> - Outbound dedup **does exist**: `MessageSender` reserves the idempotency key with Redis `SET NX` (24h TTL), contrary to
>   the "no dedup in V1" amendment near the end of this ADR.
> - A `paused_subflow` session is never classified: `findActiveForContact` selects only active, waiting and paused
>   sessions, so the waiting child is resumed instead. `SessionStateRouter` maps `paused_subflow` to `DropBusy`
>   defensively, not to a silent drop.

**Status:** Accepted
**Date:** Апрель 2026
**Контекст:** FaPost Phase 2 — Flow Engine
**Связанные документы:** `docs/reference/specs/flow-engine/`, Platform Architecture v2.2

---

## Context

Flow Engine обрабатывает входящие сообщения от пользователей через очередь и worker pool. Возникает несколько concurrent-проблем:

1. **Concurrent inputs от одного контакта.** Юзер написал «Иван», бот обрабатывает (call к API, занимает 5 сек), юзер дописал «Петров». Без защиты — два worker'а параллельно обрабатывают одну session, race condition на state.

2. **Worker crashes mid-execution.** Send_message отправлен, worker упал до save session.version. Job retry → новый worker → потенциально дубль исходящего.

3. **Long-running операции.** Call к медленному API занимает > 30 сек. Distributed lock с TTL=30s истекает — second worker может взять. Optimistic lock на session.version — second line of defense, но грязный сценарий.

4. **Stuck flows.** Юзер хочет выйти из зависшего flow или начать заново. Должны быть escape commands.

5. **UX при долгой обработке.** Юзер не видит реакции, думает что бот сломан, пишет повторно.

Также возникал вопрос о full Redis-based idempotency для исходящих (`SET NX` с TTL для дедупа). Анализ показал: distributed lock + heartbeat покрывает 99% реальных случаев, полная idempotency — overkill для V1.

## Decision

**Concurrent execution защищается distributed lock с heartbeat, не Redis-based idempotency.**

Outline:

1. **Distributed lock на (tenant, contact, assistant)** покрывает всю active execution от incoming message до next pause point (waiting_input или end). Heartbeat поддерживает lock живым при long-running.
2. **Global commands** обрабатываются **до** lock acquisition. Built-in (`/reset`, `/cancel`) hardcoded в платформе. Tenant-configurable commands через assistant settings. Module-registered commands — V1.x.
3. **Typing indicator** через ChannelAdapter abstraction. Отправляется при начале обработки и перед long-running ноds. Heartbeat refresh для каналов с TTL индикатора.
4. **Lock acquisition с backoff retry** (3 попытки × 2s) перед drop. После исчерпания — user-facing notice «бот занят».
5. **Drop policy для concurrent inputs** определяется session state на момент получения lock — see routing pipeline.
6. **Idempotency contract минимальный.** Параметр `idempotencyKey` присутствует в MessageSender и CallContext интерфейсах для forward-compatibility, но Redis-based dedup в V1 не реализуется.

## Rationale

### Distributed lock покрывает active execution целиком

Альтернатива: lock per node (взял → выполнил ноду → release → следующая нода → опять взял). Дешевле для long flows, но создаёт окна между нодами где second worker может взять lock и race на session state.

**Целостный lock проще:**
- Worker удерживает lock от resume до next waiting_input/end
- Между этими точками никто не может вмешаться
- Heartbeat компенсирует TTL
- Optimistic lock на session.version — backup защита если heartbeat fails

Trade-off: workers могут долго держать lock в плохо спроектированных flows с длинными цепочками call. В V1 acceptable — реальные flows редко имеют > 30 сек active execution.

### Global commands до lock

Если flow завис (worker не отвечает, lock не освобождается, heartbeat почему-то живёт), юзер должен иметь возможность сделать `/reset`. Если команды проходят через тот же lock как обычные сообщения — `/reset` сам зависает.

Поэтому: **command match — synchronous check без lock.** Если match — отдельная процедура termination (forceful release lock + session terminate).

### Typing indicator — UX, не защита

Не защищает от чего-либо в state. Просто показывает юзеру что бот живой. Уменьшает frequency повторных сообщений. Покрывает 80% UX issues при долгой обработке.

Долгие операции которые контент-менеджер знает заранее — он может сам поставить «думаю...» send_message перед call. Платформа не пытается angularly решать через автоматику.

### Idempotency contract без implementation

Запас на будущее. Когда реальные кейсы покажут need (broadcast рассылки на 10k+ контактов, financial calls) — добавим Redis SET NX dedup без breaking changes к интерфейсам.

В V1 — distributed lock покрывает.

## Distributed Lock Strategy

### Scope

```
Lock key: session_lock:{tenant_id}:{contact_id}:{assistant_id}
TTL: 30 seconds (initial)
Heartbeat interval: 10 seconds
Heartbeat extension: + 30 seconds (refresh TTL to 30s)
```

Один lock per (tenant, contact, assistant). Cross-assistant — отдельные locks (контакт может говорить с двумя ассистентами параллельно).

### Acquisition

```php
final class SessionLockManager
{
    public function acquire(LockScope $scope, int $ttlSeconds = 30): ?LockHandle
    {
        $key = $this->buildKey($scope);
        $token = Str::uuid()->toString();

        // SET NX with TTL
        $acquired = $this->redis->set(
            $key,
            $token,
            ['nx', 'ex' => $ttlSeconds]
        );

        if (!$acquired) {
            return null;
        }

        return new LockHandle($key, $token, $ttlSeconds);
    }

    public function release(LockHandle $handle): bool
    {
        // Lua script: release only if token matches
        return $this->redis->eval(
            'if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("DEL", KEYS[1])
            else return 0 end',
            1,
            $handle->key,
            $handle->token
        );
    }
}
```

### Heartbeat

Long-running execution требует периодическое продление TTL. Worker запускает heartbeat task параллельно обработке:

```php
final class LockHeartbeat
{
    public function start(LockHandle $handle, int $intervalSeconds = 10): HeartbeatTask
    {
        $task = new HeartbeatTask($handle, $intervalSeconds);

        // Schedule periodic extension
        $task->scheduleNext(function () use ($handle) {
            $this->extend($handle);
        });

        return $task;
    }

    public function extend(LockHandle $handle): bool
    {
        // Extend TTL only if we still own the lock (token match)
        return $this->redis->eval(
            'if redis.call("GET", KEYS[1]) == ARGV[1] then
                return redis.call("EXPIRE", KEYS[1], ARGV[2])
            else return 0 end',
            1,
            $handle->key,
            $handle->token,
            $handle->ttlSeconds
        );
    }
}
```

Если extend fails (token mismatch — кто-то другой взял lock после нашего expire) — heartbeat task завершается. Worker узнаёт через optimistic lock на session.version при попытке save.

### Acquisition retry с backoff

```php
final class LockAcquisitionPolicy
{
    private const MAX_ATTEMPTS = 3;
    private const RETRY_DELAY_MS = 2000;

    public function acquireWithRetry(LockScope $scope): ?LockHandle
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $handle = $this->lockManager->acquire($scope);

            if ($handle !== null) {
                return $handle;
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                usleep(self::RETRY_DELAY_MS * 1000);
            }
        }

        return null;  // exhausted retries
    }
}
```

Worker при failed acquisition переоформляет job в backoff queue Horizon вместо busy-wait. Освобождает worker thread.

### Lifecycle Coverage

Lock покрывает всю active execution:

```
Incoming message arrives →
  Worker acquires lock →
    Resume session →
      Execute node 1 → save → next node →
      Execute node 2 → save → next node →
      ... →
      Reach waiting_input or end →
  Release lock →
Next message can be processed
```

Между acquire и release session не может быть изменена другим worker. Heartbeat extends TTL пока worker работает.

## Global Commands

### Architecture

Three layers, по приоритету lookup:

```
1. Platform built-in       (hardcoded in code)
2. Module-registered       (через CoreRegistrar — V1.x)
3. Tenant assistant settings (database, configurable through UI)
```

При incoming message — lookup в обратном порядке (tenant settings побеждают). Match при exact string equality.

### Built-in Commands

Hardcoded в платформе, всегда присутствуют:

| Command | Action | Default Response |
|---------|--------|------------------|
| `/reset` | terminate_session | "Диалог сброшен." |
| `/cancel` | terminate_session | "Действие отменено." |

Tenant может **override** response text через settings, но не отключить команду полностью.

```php
final class BuiltinCommandsRegistry
{
    public const COMMANDS = [
        '/reset' => [
            'action' => 'terminate_session',
            'default_response' => 'Диалог сброшен.',
            'overridable' => ['default_response'],
        ],
        '/cancel' => [
            'action' => 'terminate_session',
            'default_response' => 'Действие отменено.',
            'overridable' => ['default_response'],
        ],
    ];
}
```

### Tenant Configurable Commands

Stored в `assistants.commands` JSONB column.

Schema:

```json
[
  {
    "command": "/menu",
    "type": "start_flow",
    "flow_id": "01HQ_main_menu",
    "label": "Главное меню"
  },
  {
    "command": "/help",
    "type": "send_message",
    "text": "Этот бот помогает...",
    "label": "Помощь"
  },
  {
    "command": "/reset",
    "type": "terminate_session",
    "response": "Custom reset text",
    "label": "Сброс"
  }
]
```

### Action Types

| Type | Behavior | Required fields |
|------|----------|-----------------|
| `terminate_session` | Force-terminate active session, optional ack message | `response` (optional) |
| `start_flow` | Terminate current session, start specified flow | `flow_id` |
| `send_message` | Send single message без flow start | `text` или `message_id` |

В V1 — только эти три типа. Расширения (emit_event, call_action) — V1.x.

### Validation

При сохранении assistant.commands:
- `command` — строка начинающаяся с `/`, без пробелов
- Уникальность commands в рамках одного assistant
- `flow_id` (если type=start_flow) — существует и принадлежит этому tenant
- Tenant override built-in commands — разрешено только для overridable полей (response text)

### Module-Registered Commands

V1.x feature. Module через CoreRegistrar:

```php
$registrar->commands()->register(new HrEmployeesCommand());
```

В V1 — пропускаем. Контракт CoreRegistrar готов (наследуется из общей архитектуры extension points).

### Existing /reset Implementation

Текущий `/reset` уже реализован в коде. После acceptance этого ADR требуется:

**Подзадача в рефакторинг (отдельная task):**
- Review текущей реализации `/reset`
- Migrate под новую command architecture (built-in registry + pipeline integration)
- Сохранить behavior (terminate session, ack message)
- Тесты на backward compat

Эта подзадача не блокирует acceptance ADR. Текущий `/reset` продолжает работать до миграции.

## Message Routing Pipeline

Полный flow incoming message:

```
incoming message arrives
    │
    ▼
┌─────────────────────────────────────────┐
│ Step 1: Global Command Match            │
│ (Pre-lock, synchronous check)            │
├─────────────────────────────────────────┤
│ Lookup priority:                         │
│  1. Tenant assistant.commands            │
│  2. Module-registered (V1.x)             │
│  3. Platform built-in                    │
│                                          │
│ Match? ──Yes──► Execute command          │
│                  │                       │
│                  ▼                       │
│         (Acquire lock force-mode,        │
│          terminate session if needed,    │
│          execute action,                 │
│          release lock)                   │
│                  │                       │
│                  ▼                       │
│              [DONE]                      │
│                                          │
│ No match? ──► Continue                   │
└─────────────────────────────────────────┘
    │
    ▼
┌─────────────────────────────────────────┐
│ Step 2: Send Typing Indicator            │
│ ChannelAdapter::indicateProcessing()     │
└─────────────────────────────────────────┘
    │
    ▼
┌─────────────────────────────────────────┐
│ Step 3: Lock Acquisition                 │
│ Backoff retry: 3 attempts × 2s           │
├─────────────────────────────────────────┤
│ Acquired? ──Yes──► Continue              │
│                                          │
│ Exhausted? ──► Send "бот занят" notice   │
│                Stop typing                │
│                Drop message               │
│                [DONE]                    │
└─────────────────────────────────────────┘
    │
    ▼
┌─────────────────────────────────────────┐
│ Step 4: Session State Check              │
├─────────────────────────────────────────┤
│ Find active session                      │
│  for (tenant, contact, assistant)        │
│                                          │
│ State?                                   │
│  ├─ waiting_input ──► Process input      │
│  ├─ active ──► (rare, lock just released)│
│  │              Drop message              │
│  ├─ paused / paused_subflow ──► Drop     │
│  ├─ ended / expired / failed ──► Trigger │
│  └─ no session ──► Trigger resolution    │
└─────────────────────────────────────────┘
    │
    ▼
┌─────────────────────────────────────────┐
│ Step 5: Flow Execution                   │
├─────────────────────────────────────────┤
│ Start lock heartbeat                     │
│                                          │
│ Loop:                                    │
│   Execute current node                   │
│   ├─ Before long-running: refresh typing │
│   ├─ Save session (version++)            │
│   ├─ Resolve next node from edge         │
│   └─ Continue                            │
│                                          │
│ Stop conditions:                         │
│  ├─ waiting_input ──► break              │
│  ├─ end ──► break                        │
│  └─ failure ──► mark failed, break       │
│                                          │
│ Stop heartbeat                           │
└─────────────────────────────────────────┘
    │
    ▼
┌─────────────────────────────────────────┐
│ Step 6: Cleanup                          │
├─────────────────────────────────────────┤
│ Stop typing indicator                    │
│ Release lock                             │
└─────────────────────────────────────────┘
    │
    ▼
[DONE]
```

### Drop Policy

При drop message — поведение зависит от reason:

| Reason | User-facing notice |
|--------|---------------------|
| Lock acquisition timeout (3 retries failed) | "Бот занят, попробуйте через несколько секунд." (configurable) |
| Session in active/paused state when lock acquired | Silent drop (rare, lock semantics обычно не позволяют) |
| Session in paused_subflow | Silent drop (message направлен child через routing rules — см. Subflow ADR) |

Notice text — `assistant.busy_message` field, default value provided. Tenant может кастомизировать.

### Trigger Resolution

Когда session нет или in terminal state — message может стартовать новый flow через triggers (см. Platform Architecture, раздел 7.1). Подробности routing — task 14 (Flow Triggers).

## Typing Indicator Contract

### ChannelAdapter Abstraction

```php
namespace Fapost\Foundation\Contracts\Channel;

interface ChannelAdapterInterface
{
    // ... existing methods ...

    /**
     * Show "processing" indicator to user.
     * Implementation depends on channel:
     *  - Telegram: sendChatAction(typing)
     *  - WhatsApp: typing presence (if supported)
     *  - Other: no-op acceptable
     *
     * Returns handle for stopping the indicator.
     */
    public function indicateProcessing(string $chatId): ProcessingIndicatorHandle;

    /**
     * Stop processing indicator (best effort).
     * Some channels auto-clear when next message arrives,
     * stop() may be no-op for them.
     */
    public function stopProcessing(ProcessingIndicatorHandle $handle): void;
}
```

### Heartbeat Mechanism

Telegram typing TTL = 5 секунд. Long-running operations требуют refresh.

```php
final class TypingIndicatorService
{
    public function start(
        ChannelAdapterInterface $adapter,
        string $chatId
    ): TypingSession {
        $handle = $adapter->indicateProcessing($chatId);

        return new TypingSession(
            adapter: $adapter,
            chatId: $chatId,
            handle: $handle,
            refreshInterval: 4,  // seconds, < Telegram's 5s TTL
        );
    }
}

final class TypingSession
{
    private bool $stopped = false;

    public function refresh(): void
    {
        if ($this->stopped) return;

        $newHandle = $this->adapter->indicateProcessing($this->chatId);
        $this->handle = $newHandle;
    }

    public function stop(): void
    {
        $this->stopped = true;
        $this->adapter->stopProcessing($this->handle);
    }
}
```

### When Indicator is Sent

| Trigger | Action |
|---------|--------|
| Worker takes job from queue | start typing |
| Before call node execution | refresh typing |
| Before rag_query node | refresh typing |
| send_message node executes | indicator auto-clears (real message takes precedence) |
| waiting_input reached | stop typing (юзер должен видеть что бот ждёт его, не печатает) |
| end node | stop typing |
| Long-running active state (heartbeat) | refresh typing каждые 4s |

### Channel Variations

- **Telegram:** `sendChatAction` API. TTL 5s. Refresh каждые 4s.
- **WhatsApp Business API:** Presence updates. Behavior depends on Cloud API vs On-Premises. Adapter implements best-effort.
- **Future channels:** if no support — no-op. Adapter returns dummy handle.

## Failure Modes

### Worker crash mid-execution

**Scenario:** Worker отправил send_message → провайдер принял → worker упал перед save session.

**Behavior:**
1. Job retries (Horizon redelivery)
2. New worker tries acquire lock
3. Old worker's lock либо уже истёк (TTL), либо token mismatch при попытке release
4. New worker acquires lock
5. New worker checks session state — current_node все ещё на той же ноде (save не прошёл)
6. New worker re-executes node → **send_message отправляется второй раз**

**Impact:** дубль исходящего сообщения.

**V1 mitigation:**
- Окно crash window малое (~100ms между send и save)
- Реальная frequency низкая (worker crashes — редкое событие)
- Distributed lock prevents concurrent execution; race window существует только при crash + retry

**Documented as known limitation в V1.** Полное решение — Redis-based idempotency с attempt_id model — V1.x когда понадобится (broadcast scenarios, financial calls).

### Redis unavailable

**Scenario:** Redis недоступен (network partition, restart) в момент lock acquisition.

**Behavior:**
- Lock acquisition throws → worker не может обработать message
- Job retries through Horizon backoff
- При восстановлении Redis — все pending jobs обработаются

**Impact:** delay в обработке messages, не data corruption.

**V1 stance:** Redis — критическая инфраструктура. Failure = incident. Operator alerts. Нет smart fallback в коде.

### Lock heartbeat fails

**Scenario:** Worker теряет ability обновлять lock TTL (Redis hiccup, GC pause).

**Behavior:**
- Lock истекает после 30s
- Second worker может acquire lock
- First worker продолжает execution с stale lock state
- При попытке save session — optimistic lock на version отлавливает (second worker уже обновил)
- First worker получает retry signal, перечитывает session, видит изменения, делает что нужно

**Impact:** возможно duplicate execution одной ноды (но optimistic lock защищает от дублирования writes).

**V1 acceptable.** Optimistic lock — second line of defense, работает корректно.

### /reset во время active execution

**Scenario:** Юзер делает `/reset`, в этот момент worker A обрабатывает flow.

**Behavior:**
1. Command match — synchronous check, без lock
2. Command handler пытается acquire lock с force-mode (timeout 5s)
3. Если lock acquired (worker A освободил или TTL истёк) — terminate session, return ack
4. Если timeout — **forced unlock** (DELETE lock key) + UPDATE session SET status='terminated_by_user' (с version++)
5. Worker A при попытке save видит version mismatch → retry → видит status=terminated → graceful exit

**Impact:** worker A прерывается mid-flight. Зависимости (например, отправленный send_message) могут уже произойти. Это OK — `/reset` semantics предполагают «прервать что бы ни происходило».

**Edge case:** worker A в середине call к внешнему API → call продолжает выполняться, response теряется. Acceptable — сторонний API проигнорирует или отработает свою idempotency.

## Idempotency Contract (Minimal)

Контракт сохраняется для forward-compatibility, реализация Redis-based dedup откладывается.

### MessageSender

```php
namespace Fapost\Foundation\Contracts\Messaging;

interface MessageSenderInterface
{
    public function send(
        OutgoingMessage $message,
        string $idempotencyKey
    ): SentMessageResult;
}

final class SentMessageResult
{
    public function __construct(
        public readonly string $messageId,
        public readonly bool $wasDeduplicated,  // V1: всегда false
        public readonly array $providerMetadata,
    ) {}
}
```

В V1 implementation просто passes idempotencyKey в провайдер если он поддерживает (HTTP — Idempotency-Key header). Telegram игнорирует — handler не делает Redis check.

### CallContext

Already defined в Flow Engine Nodes spec:

```php
final class CallContext
{
    public function __construct(
        public readonly TenantInterface $tenant,
        public readonly FlowSessionInterface $session,
        public readonly string $idempotencyKey,
    ) {}
}
```

HTTP transport кладёт в Idempotency-Key header. Handler transport передаёт в action context. Action handlers сами решают использовать или нет.

### Key Composition

```
idempotencyKey = "{session_id}:{node_id}:{attempt_number}"
```

Где `attempt_number` — счётчик в `session.state.system.node_attempts.{node_id}`.

В V1 attempt_number — статически 1 (нет automatic increment, потому что нет Redis dedup). Контракт готов к расширению когда attempt tracking реально включится.

## V1 Scope

### В V1 поставляется

- `SessionLockManager` с acquire/release/extend
- `LockAcquisitionPolicy` с backoff retry (3 × 2s)
- Lock heartbeat (4s интервал extension)
- Built-in commands `/reset`, `/cancel` через `BuiltinCommandsRegistry`
- Tenant assistant.commands JSONB field + UI для управления (Filament resource)
- Action types: `terminate_session`, `start_flow`, `send_message`
- Message routing pipeline (6 шагов)
- ChannelAdapter `indicateProcessing` / `stopProcessing` методы
- TypingIndicatorService с heartbeat
- Drop policy с user-facing busy notice
- `assistant.busy_message` field (configurable)
- Idempotency contract (parameter в MessageSender / CallContext) **без** Redis-based dedup implementation

### Не в V1

- Module-registered commands (V1.x — когда модули появятся в Phase 4)
- Action types: `emit_event`, `call_action`, `set_attribute` (V1.x)
- Redis-based outbound dedup (V1.x по first business need: broadcast / financial calls)
- Async call execution (V2 — call как long-running с callback resume)
- Smart message routing с NLU intent classification

### Refactoring Tasks

Отдельные подзадачи возникающие из этого ADR:

1. **Refactor /reset implementation** — migrate под built-in commands registry + pipeline. Backward compat preserved. Не блокирует ADR acceptance.

## Test Strategy

### Unit Tests

- `SessionLockManager`: acquire, release, token validation, expire behavior
- `LockAcquisitionPolicy`: retry counting, backoff timing
- `BuiltinCommandsRegistry`: lookup priority, override rules
- `TypingIndicatorService`: heartbeat timing, stop semantics

### Integration Tests

- **Concurrent inputs:** simulate two messages 100ms apart, verify second waits, processes correctly when session reaches waiting_input
- **Long-running flow:** flow с call длительностью 60s, verify lock heartbeat extends TTL, lock not lost
- **Lock acquisition timeout:** simulate stuck flow, verify second worker drops with notice after 6s
- **/reset during active execution:** start flow, send /reset mid-execution, verify session terminated, ack received, no errors
- **Worker crash:** kill worker after send_message but before save, retry, verify session correctly resumed (с known limitation о возможном дубле send)
- **Typing indicator lifecycle:** start → refresh × N → stop, verify Telegram chat actions sent at expected intervals

### Failure Mode Tests

- Redis unavailable during lock acquisition → job retries through Horizon
- Heartbeat fails → optimistic lock catches concurrent worker
- Forced /reset с timeout → forced unlock works, worker A graceful exits

### Acceptance Criteria

ADR considered implemented когда:

- [ ] All unit tests pass
- [ ] All integration tests pass
- [ ] Manual test: typing indicator visible в Telegram при flow execution
- [ ] Manual test: /reset терминирует stuck flow, юзер получает ack
- [ ] Manual test: два сообщения подряд — второе обрабатывается с задержкой, не теряется (если flow быстро завершается)
- [ ] Manual test: drop notice появляется при флоу > 8 сек
- [ ] /reset refactoring task created в Notion
- [ ] Documentation в Notion обновлена (Concurrency раздел architecture)

## Consequences

### Positive

- Concurrent inputs защищены без сложной idempotency
- Long-running flows работают через heartbeat
- Юзер видит активность через typing indicator
- Escape commands (`/reset`) работают даже для stuck flows
- Tenant flexibility через configurable commands
- Forward-compatible с full idempotency (контракт готов)

### Negative

- Lock покрывает всю active execution → workers могут долго держать lock (acceptable для V1)
- Worker crash mid-send → возможный дубль исходящего (known limitation)
- Heartbeat overhead (Redis call каждые 4-10 сек на active session)
- Refresh typing каждые 4s — дополнительные API calls к Telegram

### Neutral

- Idempotency contract присутствует, но не activated → minor cognitive load для разработчиков (понимать почему параметр есть но не используется)
- /reset refactoring — отдельная подзадача, не блокирует ADR

## References

- `docs/reference/specs/flow-engine/` — спецификация Flow Engine V1
- `docs/reference/specs/flow-engine/nodes/01-send-message.md`, `06-call.md` — node specs использующие idempotencyKey
- `docs/reference/specs/flow-engine/nodes/08-subflow.md` — distributed lock semantics для child sessions
- `docs/platform/architecture/adr/08-expression-language.md` — accepted ADR (referenced для general patterns)
- Platform Architecture v2.2 — раздел 5 (Concurrency), раздел 7 (Message Pipeline)
- HandlerVersionContract — pattern для command/action versioning

---

**Document final. Implementation ready.**

---

## Связано с

- [[01-webhook-pipeline]] — диаграмма webhook pipeline
- [[08-concurrency-idempotency]] — concurrency и idempotency в платформе
- [[04-session-state-machine]] — state machine сессии
- [[README]] — обзор flow engine
- [[10-message-pipeline]] — архитектура message pipeline
- [[diagrams/01-webhook-pipeline]] — диаграмма webhook pipeline
- [[01-octane-ingress-only]] — ADR-01 (отменён): stateless ingress

---

## Amendment · Brownfield Reconciliation (April 2026)

ADR применяется как написано. Уточнения по факту существующего кода:

- **Distributed lock (`SessionLockManager`)** — net-new (Phase A-5). Существующего lock manager нет, никаких конфликтов.
- **Existing `/reset`** реализация (если есть в коде) — refactored под `BuiltinCommandsRegistry` в Phase D-3.
- **`ChannelInterface`** уже существует в foundation (`packages/fapost-foundation/src/Channel/ChannelInterface.php`); typing-методы (`indicateProcessing`/`stopProcessing`) добавляются в Phase A-6 как extension. Telegram adapter (`TelegramAdapter`) — реализует через `sendChatAction`.
- **MessageSenderInterface** — в существующем коде есть в двух местах: foundation (`packages/fapost-foundation/src/Messaging/`) и Core (`app/Domains/Flow/Contracts/`). Унификация per D-8: foundation = public contract, Core `FlowMessageSender` implements + adds `idempotencyKey` parameter.
- **Idempotency contract minimal в V1** — `idempotencyKey` присутствует в interface, но **не используется** для dedup. `attempt_number` статически = 1. Полная Redis SET NX dedup откладывается на V1.x по first business need.
- **assistants.commands / busy_message** — добавлены в migration `2026_05_06_000006` (Phase A-2 ✓). Status enum extension (`paused_subflow`, `terminated_by_user`, `expired`, `ended`) — migration `2026_05_06_000003` ✓.

См. `docs/archive/platform/plans/flow-engine/synthesis.md` D-8 для полной reconciliation matrix.
