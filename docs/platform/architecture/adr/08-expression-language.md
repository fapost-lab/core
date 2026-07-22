# ADR-08 — Expression Language Pluggability

**Status:** Accepted

**Date:** Апрель 2026

**Контекст:** FAPost Phase 2 — Flow Engine

**Связанные документы:** Flow Engine Nodes V1, Platform Architecture v2.2

---

# Context

Flow Engine выполняет user-defined логику через ноды. В нескольких местах требуется evaluation выражений с подстановками значений из state:

- `SendMessage.text` — текст сообщения с подстановками контактных данных
- `Call.target`, `Call.parameters` — построение URL и параметров запроса
- `Call.transport_options` — динамические headers, auth tokens
- `RagQuery.query` — формирование prompt для RAG
- `Assign.value` — вычисление значения для записи
- `EmitEvent.payload` — данные события
- Validation error messages в Input ноде

Эти выражения — user input, сохраняются в `flow_definitions.nodes` JSON и выполняются runtime.

Conditional logic (Branch) обрабатывается отдельно через **structured JSON** (OperandRef + Operator enum) — это инвариант, не зависит от выбора expression engine.

# Decision

**Expression evaluation реализуется как pluggable strategy** через `ExpressionEngineInterface`.

**Outline:**

1. Контракт `ExpressionEngineInterface` живёт в `fapost/foundation`
2. Реестр `ExpressionEngineRegistry` живёт в core, in-memory, boot-time
3. Engine выбирается **per-tenant** через `tenant.settings.expression_engine`
4. Default engine — `'template'` (built-in)
5. `flow_definitions.expression_engine` — snapshot field, **immutable** для конкретного definition
6. Active sessions резолвят engine через `flow_definition.expression_engine`, не через текущий tenant config
7. Conditions — всегда structured JSON, engine-agnostic
8. V1 ships только с built-in engine `'template'` (regex `{{path}}` substitution)
9. Дополнительные engines (Symfony ExpressionLanguage, Twig, custom) добавляются по first business need в V1.x+

# Rationale

## Per-tenant config, не per-flow

UI пользователя (бизнес-юзер строящий flows) не должен думать о выборе expression engine. Это инфраструктурное решение, принимаемое:

- Админом SaaS платформы (для своих tenants)
- Self-hosted клиентом (для своей инсталляции)

Per-tenant даёт:

- Enterprise клиенту custom engine без влияния на других
- Простой UI: всегда один синтаксис в рамках tenant
- Очевидную точку конфигурации (tenant settings)

## Snapshot field обязателен

Без `flow_definition.expression_engine` running sessions ломаются при смене tenant config:

- Session работает по `flow_definition_id` (immutable snapshot)
- Если engine резолвится из текущего tenant config — admin сменил config, существующие sessions с другим синтаксисом expressions ломаются
- С snapshot field — каждый flow_definition forever работает на своём engine, независимо от config drift

## Migration — admin responsibility

Платформа **не предоставляет** auto-migration expression strings между engines. Причины:

- Разные engines имеют разные синтаксисы и edge cases
- AST-level transformation требует full parsers для обоих engines
- Risk silent semantic drift: автоматически конвертированное выражение может работать иначе
- Migrations такого рода — operational concern, не platform concern

Admin при смене engine отвечает за:

- Пересохранение flow_definitions с переписанными expressions под новый синтаксис
- Тестирование переписанных flows
- Координацию rollout (можно держать несколько engines одновременно через snapshot)

## Conditions — structured JSON

Branch.cases используют OperandRef + Operator enum:

```json
{
  "left": {"source": "contact", "path": "age"},
  "op": "gt",
  "right": {"literal": 18}
}
```

Это — **инвариант, не зависящий от expression engine**. Причины:

- Validation тривиальна (JSON Schema)
- Refactoring (rename field) работает через find/replace по path
- Multilingual UI (operators локализуются как UI elements, не как keywords)
- Нет attack surface парсинга строк
- Engine choice не влияет на conditional logic

## Built-in 'template' engine

Минимальный достаточный для V1:

```
"Привет, {{contact.first_name}}, ваш заказ #{{flow.order_id}}"
```

Поддерживает только substitution. Без операторов, функций, арифметики.

Покрывает 95% реальных кейсов в HR/customer service flows. Сложные операции (concat, math, transform) — добавляются как **structured operations** в Assign value, а не через expression engine.

## Pluggability через интерфейс

Стоимость в V1 — небольшая (~200-300 LOC + tests). Польза значительная:

- Symfony ExpressionLanguage добавляется без переделки nodes/handlers
- Plugins могут регистрировать custom engines
- Per-tenant выбор — natural extension
- Versioning — через registration новых engines с новыми id

Альтернатива (hardcode template engine) — гарантированный rework если понадобится Symfony EL.

# Contract

## ExpressionEngineInterface

Living in `fapost/foundation`:

```php
namespace FAPost\Foundation\Contracts\Flow\Expression;

interface ExpressionEngineInterface
{
    /**
     * Уникальный идентификатор engine в registry.
     * 'template', 'symfony_el', 'twig', etc.
     */
    public static function id(): string;

    /**
     * Версия engine. Bump при breaking changes синтаксиса.
     */
    public static function version(): int;

    /**
     * Render/evaluate source string в значение.
     *
     * Template: "Привет, {{contact.name}}" → "Привет, Иван"
     * Symfony EL: "contact.name" → "Иван", "contact.age + 1" → 31
     *
     * @throws ExpressionEvaluationException
     */
    public function evaluate(string $source, ExpressionContext $context): mixed;

    /**
     * Validate syntax при сохранении flow_definition.
     * Не выполняет, только проверяет parsability и references.
     *
     * @throws ExpressionSyntaxException
     */
    public function validate(string $source): void;

    /**
     * Извлечь все referenced paths из source.
     * Используется для cache invalidation, group discovery, refactoring.
     *
     * "Привет, {{contact.name}} из {{contact.city}}"
     *   → ['contact.name', 'contact.city']
     *
     * @return list<string>
     */
    public function extractReferences(string $source): array;
}
```

## ExpressionContext

```php
namespace FAPost\Foundation\Contracts\Flow\Expression;

final class ExpressionContext
{
    public function __construct(
        public readonly TenantInterface $tenant,
        public readonly ContactInterface $contact,
        public readonly FlowSessionInterface $session,
        public readonly StateReader $stateReader,
    ) {}

    /**
     * Resolve path through state reader.
     * 'contact.first_name' → contact attributes
     * 'flow.x' → session state
     * 'rag.confidence' → session state
     * 'module.hr.department' → DataAccessor
     */
    public function read(string $path): mixed
    {
        return $this->stateReader->read($path, $this);
    }
}
```

## ExpressionEngineRegistry

Living в core:

```php
namespace FAPost\Core\Flow\Expression;

final class ExpressionEngineRegistry
{
    /** @var array<string, ExpressionEngineInterface> */
    private array $engines = [];

    public function register(ExpressionEngineInterface $engine): void
    {
        $id = $engine::id();
        if (isset($this->engines[$id])) {
            throw new LogicException(
                "Expression engine '{$id}' already registered. " .
                "Existing: " . get_class($this->engines[$id]) . ", " .
                "Conflicting: " . get_class($engine)
            );
        }
        $this->engines[$id] = $engine;
    }

    public function get(string $id): ExpressionEngineInterface
    {
        return $this->engines[$id]
            ?? throw new ExpressionEngineNotFoundException($id);
    }

    public function has(string $id): bool
    {
        return isset($this->engines[$id]);
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->engines);
    }
}
```

Conflicts cause boot failure (consistent с другими registries).

## Built-in TemplateEngine

V1 implementation:

```php
namespace FAPost\Core\Flow\Expression\Engines;

final class TemplateEngine implements ExpressionEngineInterface
{
    private const PLACEHOLDER_PATTERN = '/\{\{\s*([\w.]+)\s*\}\}/';

    public static function id(): string { return 'template'; }
    public static function version(): int { return 1; }

    public function evaluate(string $source, ExpressionContext $context): string
    {
        return preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            function (array $match) use ($context): string {
                $value = $context->read($match[1]);
                return $this->stringify($value);
            },
            $source
        );
    }

    public function validate(string $source): void
    {
        // Проверка что placeholders syntactically valid paths
        if (preg_match_all(self::PLACEHOLDER_PATTERN, $source, $matches)) {
            foreach ($matches[1] as $path) {
                $this->validatePath($path);
            }
        }
    }

    public function extractReferences(string $source): array
    {
        if (!preg_match_all(self::PLACEHOLDER_PATTERN, $source, $matches)) {
            return [];
        }
        return array_values(array_unique($matches[1]));
    }

    private function validatePath(string $path): void
    {
        // Path должен начинаться с известного namespace
        // и содержать только alphanumeric segments
        if (!preg_match('/^(contact|flow|system|rag|call|module)\.[\w.]+$/', $path)) {
            throw new ExpressionSyntaxException(
                "Invalid path '{$path}'. Must start with namespace " .
                "(contact, flow, system, rag, call, module)."
            );
        }
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            is_null($value) => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
```

# Tenant Configuration

## Tenant settings field

```php
// tenant.settings JSONB
{
    "expression_engine": "template",
    "timezone": "Europe/Moscow"
}
```

`expression_engine` — optional, default `'template'`.

## Tenant boot validation

```php
final class TenantExpressionEngineValidator
{
    public function __construct(
        private readonly ExpressionEngineRegistry $registry,
    ) {}

    public function validate(TenantInterface $tenant): void
    {
        $engineId = $tenant->settings['expression_engine'] ?? 'template';

        if (!$this->registry->has($engineId)) {
            throw new TenantConfigException(
                "Tenant {$tenant->id()} configured with unknown " .
                "expression_engine '{$engineId}'. " .
                "Available engines: " . implode(', ', $this->registry->ids())
            );
        }
    }
}
```

Вызывается в `CoreBootstrap` — fail-fast если config указывает на несуществующий engine.

# Flow Definition Snapshot

## Schema

```sql
ALTER TABLE flow_definitions
    ADD COLUMN expression_engine text NOT NULL;

CREATE INDEX idx_flow_definitions_engine
    ON flow_definitions (expression_engine)
    WHERE is_active = true;
```

## Save semantics

При сохранении flow_definition:

1. Resolve current tenant engine: `$engine = $tenant->settings['expression_engine'] ?? 'template'`
2. Validate engine exists в registry — иначе error
3. Validate все expressions в nodes через `$engine->validate($source)`
4. INSERT flow_definition с `expression_engine = $engine->id()`

После save — field immutable. Edit definition = новая version row, может иметь другой engine_id если tenant config изменился.

## Runtime resolution

```php
// В FlowEngine при выполнении node:
$definition = $session->definition();
$engine = $registry->get($definition->expressionEngine);

$context = new ExpressionContext($tenant, $contact, $session, $stateReader);

// Используется handler-ами для evaluate
$rendered = $engine->evaluate($node->config['text'], $context);
```

Engine приходит в `NodeExecutionContext` уже резолвленный — handler не выбирает движок, использует переданный.

# Migration Policy

## Engine class removal

Engine класс **нельзя удалить из кода** пока:

- Существуют active flow_definitions с `expression_engine = <id>`
- Существуют running flow_sessions со ссылкой на такие definitions

Аналогично HandlerVersionContract. Operator при upgrade платформы должен проверить:

```sql
SELECT DISTINCT expression_engine
FROM flow_definitions
WHERE is_active = true OR id IN (
    SELECT flow_definition_id FROM flow_sessions
    WHERE status IN ('active', 'waiting_input', 'paused', 'paused_subflow')
);
```

Все ID в результате — engines которые **обязаны** быть в коде.

## Tenant config change

Admin может сменить `tenant.settings.expression_engine` в любой момент:

- Existing flow_definitions продолжают работать со своим snapshot engine
- При попытке save existing definition (новая version) — система использует **новый** engine, validates expressions против него; если они не парсятся — admin получает validation errors и должен переписать
- Новые flow_definitions сохраняются с новым engine

**Migration tools** (auto-rewrite expressions между engines) — **не предоставляются платформой**. Admin делает руками или пишет custom скрипты.

## Engine version upgrade

Внутри одного engine id — engine может эволюционировать:

- Backward-compatible изменения — version не bumps, expressions работают как раньше
- Breaking changes — рекомендуется регистрировать как **новый engine id** (например, `symfony_el_v7`), чтобы существующие flows с `symfony_el` (v6) продолжали работать
- Альтернатива — version field в snapshot (`expression_engine_version`) — рассматривается в V2 если возникнет потребность

В V1 — assume engines stable, version в registry для observability.

# Plugin Support

Plugin может регистрировать свой engine через `CoreRegistrar`:

```php
final class MyPluginRegistrar implements ModuleRegistrarInterface
{
    public function register(CoreRegistrar $registrar): void
    {
        $registrar->expressionEngines()->register(new MyCustomEngine(...));
    }
}
```

После регистрации — engine доступен по своему id, tenants могут выбрать его в `settings.expression_engine`.

Plugin lifecycle:

- Plugin disabled/removed → engine исчезает из registry
- Tenants ссылающиеся на этот engine → boot fail
- Operator должен либо вернуть plugin, либо мигрировать tenants на другой engine

# Failure Modes

## При сохранении flow_definition

- Tenant engine resolves в registry — OK
- Tenant engine не resolves — `TenantConfigException`, save refuses
- Expression syntax invalid для engine — `ExpressionSyntaxException`, save refuses с указанием невалидного expression

## При выполнении node

- Engine не resolves в registry — runtime error, session failed (engine был удалён без миграции flow_definitions — operator violation)
- Expression evaluation fails — `ExpressionEvaluationException`, session failed
- Path в expression не существует в state — engine-specific behavior:
    - Template: возвращает пустую строку (graceful)
    - Symfony EL: возможно exception (зависит от engine config)

Engine реализация документирует свою failure semantics для unknown paths.

# V1 Scope

В V1 поставляется:

- `ExpressionEngineInterface` в foundation
- `ExpressionEngineRegistry` в core
- `ExpressionContext` DTO
- `TemplateEngine` (single built-in implementation)
- `flow_definitions.expression_engine` column + migration
- `tenant.settings.expression_engine` documentation
- Tenant boot validation
- Save-time validation expressions через engine

Не в V1:

- Symfony ExpressionLanguage engine
- Twig engine
- Per-flow engine override (always per-tenant в V1)
- Auto-migration tools между engines
- Engine version field в snapshot (только id)
- UI выбор engine в admin panel (config через файл/env в V1)

# Open Questions Closed

Этот ADR закрывает open question из `specs/flow-engine/_snapshot-v1.0.md` раздел 12.1:

> Expression engine: Symfony ExpressionLanguage / Twig / custom?
> 

**Resolution:** pluggable interface, V1 ships built-in `template` engine, дополнительные — по необходимости.

# Consequences

## Positive

- Future-proof: добавление Symfony EL не требует переделки nodes/handlers
- Per-tenant flexibility: enterprise клиенты могут выбрать свой engine
- Simple V1: один template engine достаточно для типовых кейсов
- Snapshot immutability защищает running sessions от config drift
- Pluggability открыта для plugins без изменения core

## Negative

- Дополнительная абстракция в V1 (~200-300 LOC + tests)
- Snapshot field `expression_engine` — обязательный, increases schema complexity
- Operator concern: tracking engine references при upgrade платформы
- Admin responsibility: migration expressions между engines manual

## Neutral

- UI логика — engine-aware (читает tenant config для подсказок синтаксиса)
- Plugins — могут вводить custom engines, что extends matrix совместимости

---

## Связано с

- [[08-expression-language]] — детальный ADR по expression language
- [[02-common-concepts]] — общие концепции flow engine
- [[01-state-model]] — state model, в котором используются выражения
- [[specs/flow-engine/00-overview]] — обзор flow engine
- [[specs/flow-engine/02-common-concepts]] — общие концепции нод

# References

- Flow Engine Nodes V1 — раздел 3.2 Expression
- Flow Engine Nodes V1 Review Responses — обсуждение синтаксиса в Variable definition
- ADR Handler Versioning Contract — аналогичный pattern для node handlers
- Platform Architecture v2.2 — раздел 9 (пакетная экосистема)