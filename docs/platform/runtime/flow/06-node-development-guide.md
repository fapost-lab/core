# 06. Node Development Guide (подробно)

Этот документ - рабочая инструкция для инженеров, которые добавляют новые ноды в flow engine.

Цель: чтобы новая нода была:

- корректной по runtime-поведению,
- совместимой с текущими flow definition,
- безопасной для retry/concurrency,
- понятной в builder UI.

---

## 1) Ментальная модель: что такое нода

Нода = связка из 4 частей:

1. **Definition JSON** (`flow_definitions.nodes[]`) - данные ноды в графе.
2. **Handler** (PHP класс) - исполняет бизнес-логику ноды.
3. **Edges/outputs** (`flow_definitions.edges[]`) - переходы к следующим нодам.
4. **Builder config UI** (Vue) - редактирование `node.config`.

Движок исполняет только то, что в snapshot definition (`flow_definition_id`) текущей сессии.

---

## 2) Контракт handler-а (backend)

В проекте ноды реализуются через `NodeHandlerInterface` (обычно наследованием от `AbstractVersionedHandler`).

Минимум, который должен определить handler:

- `TYPE` (константа, строковый идентификатор ноды, например `send_message`).
- `version(): int` (текущая версия handler-а).
- `execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult`.

Часто также переопределяют:

- `label(): string` (как называется в builder),
- `category(): string` (группа в палитре),
- `configSchema(): array` (схема полей для UI).

### Почему лучше `AbstractVersionedHandler`

`AbstractVersionedHandler` уже даёт базовое поведение:

- `type()` берётся из `TYPE`,
- `supportedVersions()` по умолчанию = `[version()]`,
- дефолтные `label()`/`category()`/`configSchema()`.

Это уменьшает риск кривой реализации.

---

## 3) Как handler попадает в runtime

`NodeHandlerRegistry` — синглтон на весь Horizon worker, поэтому он хранит не инстансы
handler-ов, а **class-string**: инстанс закреплял бы за собой scoped-зависимости той job-ы,
которая первой построила registry, и это была бы утечка между тенантами (см. ADR-0001,
`.ai/knowledge/adr/0001-node-handlers-built-per-scope.md`).

Core handler-ы перечисляются в `FlowServiceProvider::registerCoreNodeHandlers()`:

```php
private function registerCoreNodeHandlers(NodeHandlerRegistry $registry): void
{
    $handlers = [
        SendMessageNodeHandler::class,
        InputNodeHandler::class,
        // ...
        SetTagNodeHandler::class,
    ];

    foreach ($handlers as $handlerClass) {
        $registry->register($handlerClass);
    }
}
```

- `register(class-string)` строит одноразовый инстанс только чтобы прочитать `type()`/`version()`,
  а хранит только класс.
- `resolve()` и `all()` строят новый инстанс на **каждый вызов** через порт
  `NodeHandlerFactoryInterface` (`app/Domains/Flow/Contracts/NodeHandlerFactoryInterface.php`).
  Реализация — `ContainerNodeHandlerFactory` (`app/Infrastructure/Flow/ContainerNodeHandlerFactory.php`) —
  резолвит через контейнер, а значит всегда попадает на scoped-биндинги job-ы, которая выполняется
  прямо сейчас.
- Handler получает коллабораторов как обычные constructor-зависимости — никаких resolver-closures
  в сигнатуре конструктора (это запрещено архитектурным тестом
  `FlowRuntimeIsolationTest::test_node_handlers_do_not_take_resolver_closures`).
- После boot (`FlowServiceProvider::boot()`) registry freeze-ится (`freeze()`), поздняя регистрация
  запрещена.

`NodeHandlerRegistry::register()` дополнительно проверяет:

- у handler-а нет дубликата ключа `type@version`,
- `version()` входит в `supportedVersions()`.

Если нарушено - получишь `LogicException` уже на boot.

---

## 4) Формат ноды в `flow_definitions.nodes`

Обязательные поля, с которыми реально работает runtime:

- `id: string`
- `type: string`
- `version: int`
- `config: object`

Дополнительно могут быть:

- `label` (для UI),
- `required_transitions` (для validator-а),
- любые UI-only поля, которые engine игнорирует.

### Пример

```json
{
  "id": "f0f7b8e6-7fdf-4f44-9ccd-fd57e33a6d4e",
  "type": "input",
  "version": 1,
  "label": "Ask Email",
  "config": {
    "save_to": "flow.user_email"
  },
  "required_transitions": ["default"]
}
```

---

## 5) Outputs/edges: как нода выбирает следующий шаг

Важно: handler **не возвращает `next_node_id` напрямую**.

Он возвращает `sourceHandle` через `NodeExecutionResult`.
Дальше `FlowGraphResolver::resolveNextNode(...)` ищет edge:

- `source_node_id == текущая нода`
- `transition == sourceHandle`

и только тогда находит `target_node_id`.

### Практическое правило

- `sourceHandle` в handler-е и `edge.transition` в definition должны совпадать 1:1.
- Имена handle делай стабильными (`default`, `true`, `false`, `timeout`, `error` и т.д.).

---

## 6) NodeExecutionResult: что можно вернуть

Основные статусы:

- `Executed` - нода выполнилась, может идти дальше.
- `Waiting` - ждём входящее сообщение/внешнее условие.
- `Delayed` - отложенная пауза.
- `Failed` - ошибка выполнения.
- `Finished` - явный терминал.

Дополнительные данные:

- `sourceHandle` - выбор ребра перехода.
- `stateChanges` - flat map для записи в session state (`flow.x`, `system.y`).
- `metadata` - техническая инфа для логов/диагностики.
- `logResolved` - какие значения реально прочитали при вычислении.

Мутации вне session state (contact attributes, contact language и т.п.) handler выполняет
напрямую через `ContactWriterInterface`, доступный из `NodeExecutionContext->contactWriter`
(см. §9). Легаси-массив `effects[]` был удалён — `NodeExecutionResult` его больше не содержит.

---

## 7) Как устроен `config` ноды

`node.config` - это чистые данные, которыми handler управляет поведением.

### Что важно держать в голове

- Конфиг должен быть сериализуемым в JSON.
- В `execute()` сначала нормализуй/валидируй config.
- На некорректном config кидай доменную ошибку (например, `InvalidNodeConfigException`).
- Не рассчитывай, что UI всегда прислал идеальный payload.

### `configSchema()` и builder

`NodeTypesController` отдаёт в UI:

- `type`, `version`, `label`, `category`, `config_schema`.

Builder берёт `config_schema` из registry и рисует форму.
Даже если есть кастомный Vue override, schema всё равно полезна как контракт.

---

## 8) State changes и namespaces

Движок пишет `stateChanges` в `flow_sessions.state` через `FlowSessionPersister`.

Подход:

- ключи плоские (`system.language`, `flow.user_name`, ...),
- persister раскладывает их в JSON-дерево.

### Допустимые namespace-ы (архитектурно)

Канонический список - enum `Fapost\Foundation\Flow\Enums\StateNamespace`: `system`, `flow`, `rag`,
`module`, `contact`, `call`. Новый namespace добавляется только в этот enum, не в отдельные ноды.

- `flow.*`, `call.*` - открыты для записи любым handler-ом (пользовательские переменные).
- `system.*`, `rag.*` - пишут только whitelisted типы нод; whitelist -
  `SystemStateNamespacePolicy` (`app/Domains/Flow/State/SystemStateNamespacePolicy.php`),
  применяется в `FlowSessionPersister`. Сейчас `system.*`: `send_message`, `input`, `delay`,
  `notify`, `set_tag`; `rag.*`: `rag_query`. Добавление нового писателя - это осознанная
  правка whitelist-а.
- `module.*` - read-only, читается через `DataAccessorInterface`; в `stateChanges` никогда не
  попадает.
- `contact.*` - производная проекция, в `stateChanges` тоже не пишется: contact-мутации идут
  через `ContactWriter` (см. §9).

### Практика

- Для system-ключей используй существующие constants (например `SystemStateKeys::*`), не magic strings.
- Не смешивай runtime navigation и state: переходы определяются `current_node_id` + edges, не JSON state.

---

## 9) Side-effects: где граница ответственности

Handler должен быть по возможности детерминированным.

Мутации session state - через `stateChanges` (см. §8), они проходят `FlowSessionPersister` и
whitelist `SystemStateNamespacePolicy`.

Мутации вне session state (contact attributes, contact language и т.п.) handler выполняет
**напрямую**, вызывая `ContactWriterInterface`, который приходит через
`NodeExecutionContext->contactWriter` (`Fapost\Foundation\Flow\Contracts\ContactWriterInterface`).
Легаси-подход с массивом `effects[]`, которые центральный `FlowEngine::applyEffects()` разбирал
и применял, был удалён - в `NodeExecutionResult` такого поля больше нет.

Плюс такого подхода: сам handler явно объявляет свою зависимость от `ContactWriter`, а не
полагается на скрытую интерпретацию строкового `type` эффекта где-то в движке.

---

## 10) Concurrency, retry и идемпотентность

Нода обязана быть безопасной к повторному запуску.

Почему:

- job может retry-иться,
- lock может не взяться и задача переедет по backoff,
- optimistic conflict может заставить orchestration повторить попытку.

### Базовые техники

- Храни факт "уже выполнено" в `state` (как `send_message` хранит `system.sent_messages`).
- Используй стабильные idempotency keys для внешних вызовов.
- Не делай необратимых операций без защиты от дублей.

---

## 11) Версионирование нод (критично)

Правило:

- **Backward-compatible** изменение -> оставляешь ту же версию.
- **Breaking** изменение -> новая версия handler-а.

Почему: сессия зафиксирована на конкретном `flow_definition_id`, а значит и на `type+version` нод в этом snapshot.

Нельзя "тихо" поменять смысл старой версии так, чтобы сломать старые flow.

---

## 12) Валидация definition при сохранении

`FlowDefinitionValidator` проверяет:

- у каждой ноды есть `id/type/version`,
- существует зарегистрированный handler `type@version`,
- нет дубликатов нод,
- edges ссылаются только на существующие ноды,
- нет дублирующихся `transition` на одном source,
- ровно одна entry node (без входящих рёбер),
- граф достижим (без orphan nodes),
- `required_transitions` реально присутствуют.

Отдельный бизнес-кейс уже зашит:

- `send_message` + `keyboard_mode=reply` не может иметь подключённый `default` выход.

---

## 13) Builder/UI: что добавить для новой ноды

Минимальный путь:

1. Добавить handler в backend registry.
2. Убедиться, что `NodeTypesController` отдаёт новый type.
3. В builder добавить конфиг-компонент (или использовать дефолтный рендерер).
4. Обновить node-card preview (если нужно).

Сейчас в `ConfigPanel.vue` (`resources/js/builder/components/editor/ConfigPanel.vue`) есть
`OVERRIDES` map с Core-компонентами для части типов (`send_message`, `input`, `condition`/`branch`,
`assign`, `call`, `set_tag`, `notify`, `auth_request`, `loop`, `loop_end`). Порядок резолва -
Core override → vendor override (`vendorConfigs`, Solution-компоненты через Vite glob) → дженерик
`SchemaConfigRenderer`, который рисует форму по `configSchema()`.

Рекомендация:

- если нода простая -> можно жить на schema-driven форме,
- если сложная (мультиязычность, клавиатуры, вложенные структуры) -> делай кастомный override.

---

## 14) Пошаговый чеклист "добавить новую ноду"

1. Спроектировать `type`, `version`, список `sourceHandle`, и структуру `config`.
2. Реализовать handler-класс в `app/Domains/Flow/Handlers/`.
3. Добавить `configSchema()` с понятными полями.
4. Зарегистрировать handler в `FlowServiceProvider::boot()`.
5. Добавить/обновить UI-конфиг (Vue) в builder.
6. Проверить, что validator принимает definition с этой нодой.
7. Написать тесты:
    - unit на handler (happy path + invalid config + edge cases),
    - unit/feature на validator и execution path.
8. Проверить поведение при retry/повторном запуске (идемпотентность).
9. Зафиксировать документацию: expected config, outputs, stateChanges, contact-мутации через `ContactWriter`.

---

## 15) Антипаттерны (что не делать)

- Не возвращай нестабильные/рандомные `sourceHandle`.
- Не записывай magic-строки в `system.*` без констант.
- Не смешивай "UI-представление" и runtime-конфиг в одном поле без явной причины.
- Не делай handler зависимым от глобального mutable state.
- Не меняй semantics существующей версии ноды, если это breaking change.
- Не пропускай тесты на negative paths (битый config, пустые поля, отсутствующие edges).

---

## 16) Быстрый шаблон новой ноды

`configSchema()` собирается через fluent Builder API из `Fapost\Support\Builder\Schema`
(`Schema`/`Section`/`Fields\*`), а не через сырые массивы - см. §7 в `09-node-config-conventions.md`
и реальный пример в `SetTagNodeHandler` (`app/Domains/Flow/Handlers/SetTagNodeHandler.php`).

```php
final class MyCustomNodeHandler extends AbstractVersionedHandler
{
    final public const string TYPE = 'my_custom_node';

    public function version(): int
    {
        return 1;
    }

    public function category(): string
    {
        return 'Custom';
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return Schema::make()
            ->required(['value'])
            ->section(
                Section::make('main', 'My Custom Node')
                    ->fields([
                        TextField::make('value')
                            ->label('Value')
                            ->required(),
                    ]),
            )
            ->toArray();
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        $config = is_array($nodeConfig['config'] ?? null) ? $nodeConfig['config'] : [];
        $value = $config['value'] ?? null;

        if (! is_string($value) || '' === $value) {
            throw new InvalidNodeConfigException('my_custom_node: missing value');
        }

        return NodeExecutionResult::executed(
            sourceHandle: 'default',
            stateChanges: ['flow.my_custom_value' => $value],
        );
    }
}
```

Используй этот шаблон как старт, а дальше адаптируй под конкретные runtime требования.
