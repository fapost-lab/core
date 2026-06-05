# CLAUDE.md — FAPost Core

Контекст для Claude Code. Читай перед каждой задачей.

---

## Что это за проект

FAPost Core (Flow Automation Post) — платформа для построения диалоговых ассистентов в Telegram и WhatsApp.

Архитектура: FAPost Core (этот репо) + FAPost HR / FAPost {Niche} (отдельные репо) + FAPost SaaS (отдельный репо).
Сейчас разрабатывается только Core. Без SaaS, без модулей.

**Implementation plan, статусы задач, открытые/решённые вопросы:** `fapost-plan.docx`. Сюда не дублировать.

---

## Стек

- PHP 8.4
- Laravel 12
- Laravel Horizon (очереди)
- Laravel Octane (только webhook ingress, см. ADR-01)
- PostgreSQL (schema per tenant)
- Redis (кеш, очереди, locks)
- Filament (admin-панель)
- Inertia + Vue (клиентский конструктор, позже)

---

## Структура директорий

```
app/
  Domains/            — технические bounded contexts (Tenancy, Flow, Messaging, Contact, Assistant, Broadcasting)
  Features/           — built-in capabilities (Rag, AccessControl, Analytics)
  Jobs/               — оркестрационные jobs (пересекают домены)
  Http/Middleware/    — глобальные middleware
  Console/Commands/   — platform:install, feature:activate, solution:activate
  Providers/          — App, Domain, Feature ServiceProviders

resources/js/features/   — Vue компоненты per feature

database/migrations/
  landlord/           — landlord schema (tenants, plans)
  tenant/             — platform tenant migrations
  features/           — per feature migrations
```

Структура внутри каждого домена:

```
Domains/{Domain}/
  Models/
  Contracts/          — интерфейсы (source of truth для домена)
  Services/
  Jobs/
  Http/{Controllers,Requests}/
  Exceptions/
```

---

## Архитектурные законы

Жёсткие, без исключений. Нарушение = технический долг, который сломает `platform:update`.

### Tenant-aware execution

Core всегда работает внутри tenant-контекста. Tenant — базовая координата runtime, не опциональная абстракция.

Core **не знает**: сколько tenant существует, есть ли landlord DB, используется ли SaaS wrapper.

**Запрещено:** SaaS-логика, landlord lookups, `if (isSaas())`, опциональные tenant проверки. Только hard fail если
контекст не установлен.

```php
// Правильно
$tenant = $this->tenantContext->get(); // throws

// Запрещено
$tenant = $this->tenantContext->get() ?? $this->getDefaultTenant();
```

Self-hosted = один tenant, создаётся при `platform:install`. Не отдельная ветка — частный случай общей модели.

### Migration Isolation

Миграция — чистая DDL-операция. Не знает о тенантах, конфигах, модулях, состоянии.

**Запрещено в `up()`/`down()`:**

- `app()`, `config()`, `env()` (кроме connection name как константы)
- `TenantContext::get()` или любой tenant-aware сервис
- Условные ветки на module activation / feature flags
- Seed-данные зависящие от runtime состояния
- `DB::table()` поверх таблиц платформы или другого модуля (из миграции модуля)

**Enforcement:** phpat-правило в CI с задачи 03.

### Handler Version Contract

- Каждый `NodeHandler` реализует `NodeHandlerInterface`: `type()`, `version()`, `supportedVersions()`.
- Engine резолвит handler по `(type, version)` из in-memory registry — без запросов в БД.
- Backward-compatible изменение → version не меняется.
- Breaking change → version++ в новых flow, старый handler остаётся зарегистрированным.
- Сессия выполняется по своему `flow_definition_id` snapshot до конца — никогда не мигрирует.
- **Безопасное удаление handler** = `flow_active_node_stats` показывает 0 И нет `flow_sessions` со статусом `waiting`/
  `paused` с этим `(type, version)`.
- **Enforcement:** phpat-правило.

### Runtime Surface Governance

Никто кроме Core не имеет права напрямую мутировать global runtime.

| Запрещено | Правильно |
|-----------|-----------|
| `Route::get(...)` | `$registrar->routes(fn(Router $r) => ...)` |
| `Schedule::call(...)` | `$registrar->schedule(...)` |
| `Artisan::call('migrate')` | `$registrar->migrations(__DIR__ . '/Database/Migrations')` |
| `app()->bind(...)` глобально | Только через DomainServiceProvider |

`CoreRegistrar` — единственный контракт, через который Feature/Solution/Plugin взаимодействуют с runtime. Core
автоматически оборачивает routes в tenant + auth + activation middleware.

### UI Non-interference

- Filament UI читает только из `analytics_events` и существующих query scopes.
- Новые методы на доменных объектах ради UI запрещены до стабилизации runtime model.
- Задача 20 — строго read-only surface.

### Octane scope (ADR-01, март 2026)

Octane используется **только для webhook ingress** (`/webhooks/*`). Main app (`/admin`, `/assistant`, API) — PHP-FPM.

Причина: mutable scoped context (`TenantContext`, `CurrentAssistant`). Полный Octane runtime создаёт риск state leakage
при нарушении scoped lifecycle.

**Routing split** на уровне Traefik / Nginx: `/webhooks/*` → octane, всё остальное → php-fpm.

**Ограничения для Octane ingress:**

- Webhook path stateless — нет накопленного состояния между запросами.
- Tenant switch — только через `TenantSwitcher::runForTenant()` с `finally restore()`.
- Нет mutable singleton state — только scoped bindings.
- Нет session, нет Filament, нет navigation state.

Расширение Octane scope — только после полного lifecycle audit всех scoped bindings.

### ID Strategy (ADR-03)

- Все PK в tenant-схеме: ULID, хранится в PostgreSQL `uuid` типе.
- Трейт: `App\Domains\Shared\Concerns\HasUlidPrimaryKey` (HasUlids + override `newUniqueId` через
  `Str::ulid()->toRfc4122()`).
- Миграции: `$table->uuid('id')->primary()` без default — Laravel генерирует сам.
- FK: `foreignUuid('..._id')->constrained()->cascadeOnDelete()`.
- `external_id`: `uuid NULL` — отдельная колонка, только где явно нужна внешняя интеграция.
- Не трогать: `webhook_public_hash` (random string), `tenant_id` в landlord-схеме.

### Dependency direction (Foundation & Support)

`fapost/foundation` и `fapost/support` подключаются как Core, так и в Solutions/Plugins. **Они не имеют права зависеть
от Core.**

```
Core ────────┐
Solution ────┼─► Foundation, Support
Plugin ──────┘

Foundation, Support ─► Core    ❌ ЗАПРЕЩЕНО
```

- Никаких `App\*` импортов в `packages/fapost-foundation` и в `fapost/support`.
- Никаких ссылок на конкретные доменные классы Core.
- Если возникает соблазн импортировать что-то из Core — это сигнал, что либо контракт должен переехать в Foundation,
  либо примитив — в Support, либо логике место в самом Core.
- **Enforcement:** phpat-правило (TBD).

---

## Code conventions

**Общие:**

- Подход близкий к DDD, без фанатизма.
- Интерфейсы — везде где целесообразно, не ради галочки.
- `final class` по умолчанию, снимать явно если нужно наследование.
- Readonly properties где возможно.
- Enums вместо констант для фиксированных наборов значений.
- `declare(strict_types=1);` в каждом `.php` файле.
- Constructor property promotion. Не оставлять пустых `__construct()` (кроме private).
- Явные return types и type hints для всех параметров.

**Документация (обязательно):**

- Каждый генерируемый класс имеет краткий PHPDoc на классе — что это и зачем существует, в одной–двух фразах.
- Каждый публичный метод имеет PHPDoc, отражающий **что делает и почему существует**, а не *как объявлен*.
- Параметры/возвраты в PHPDoc только когда тип недостаточен (дженерики, array shapes, конкретные ограничения значений).
- Inline-комментарии — только для исключительно сложной логики. Прочее — PHPDoc или говорящие имена.
- Array shapes — через `array{key: type}` нотацию.

**Именование:**

- Интерфейсы: `{Name}Interface` (не `I{Name}`, не `{Name}Contract`).
- Services: `{Name}Service` — содержат бизнес-логику.
- Jobs: `{Verb}{Entity}Job` — `ProcessIncomingMessageJob`, `ExecuteFlowNodeJob`.
- Exceptions: `{Name}Exception` — доменные, не базовые Laravel exceptions в доменном коде.
- Enum keys: TitleCase (`FavoritePerson`, `Monthly`).

**Запрещено:**

- Бизнес-логика в Controllers и Jobs — только оркестрация.
- Eloquent в доменных сервисах напрямую — только через Repository.
- `app()`, `resolve()` внутри доменного кода — всё через DI.
- Хелперы Laravel (`config()`, `cache()`) внутри доменных сервисов — инжектить зависимости.

**Можно и нужно:**

- Eloquent Models в `Domains/{Domain}/Models/` — нормально.
- Laravel Jobs, Events, Listeners — в соответствующих папках домена.
- Facades — только в infrastructure слое (Jobs, Controllers, Providers).
- Laravel-атрибуты для регистрации/конфигурации (`#[ObservedBy]`, `#[ScopedBy]`, route/model metadata) — приоритетный
  способ. Не дублировать в ServiceProvider если оформлено атрибутом.

---

## Domain · Feature · Solution · Plugin

Четыре понятия с разной ответственностью. Смешивать — архитектурная ошибка.

| Понятие | Что это | Где живёт | Активация |
|---------|---------|-----------|-----------|
| **Domain** | Технический bounded context, всегда активен | `app/Domains/` | — (всегда on) |
| **Feature** | Built-in capability Core, активируется per tenant | `app/Features/` | `tenant_activations` |
| **Solution** | Готовое нишевое решение, всегда внешний composer-пакет | `fapost/solution-{name}` | `tenant_activations` |
| **Plugin** | Ecosystem extension от сторонних разработчиков, runtime install без deploy | `fapost/plugin-{name}` | `PluginRegistry` |

`app/Solutions/` — **не существует** в Core.

### Единая модель активации (Feature + Solution)

```php
interface ActivatableInterface
{
    public function getId(): string;       // hardcoded: 'rag', 'hr'
    public function getVersion(): string;
    public function register(CoreRegistrar $registrar): void;
    public function boot(): void;
}
```

Реестры:

- `ActivationRegistry` — global, in-memory, все установленные.
- `tenant_activations` (DB) — `tenant_id`, `activatable_id`, `type`, `is_active`.
- `TenantActivationRuntime` — per request/job, что активно для tenant.

**Активация:**

- Self-hosted: `config/features.php`, `config/solutions.php`.
- SaaS: billing plan → `tenant_activations`.

**Installed ≠ Enabled** — одинаково для Feature и Solution.

`TenantActivationRuntime` влияет на: routes, flows, screens, menu, API surface, execution rules, доступные node
handlers.

### Ownership boundary: Feature vs Solution

- Feature owns contract (например `KnowledgeQueryInterface`).
- Solution может предоставить свою реализацию через тот же contract.
- Если capability нужна только внутри одного Solution → internal solution service, **не** global Feature.

### Solution versioning — identity сильнее version

- Backward compatible change → semver, тот же solution ID.
- Breaking change → новый solution identity (`hr` vs `hr_v2`), не upgrade.
- Tenant явно мигрирует на новый identity.

### Schema lifecycle независим от activation

- `tenant_activations.is_active = false` → capability скрыта из runtime.
- Таблицы Feature/Solution в tenant schema **не удаляются**.
- Удаление schema — только явная administrative операция.

### Boot lifecycle (строгий порядок)

```
1. Core boot      → AppServiceProvider, DomainServiceProvider
                    contracts, bindings, registries, infrastructure
2. Feature boot   → FeatureServiceProvider
                    config/features.php → ActivationRegistry
3. Solution boot  → SolutionServiceProvider
                    composer packages с SolutionInterface → ActivationRegistry
4. Plugin boot    → PluginServiceProvider
                    PluginRegistry (отдельный от ActivationRegistry)
5. Tenant runtime → TenantActivationRuntime
                    ТОЛЬКО после установки tenant context
                    ActivationRegistry + tenant_activations DB → active surface
```

`register()` — только декларативно. **Запрещено:** side effects, обращения к БД, tenant-specific логика, прямые вызовы
Route/Scheduler facades.

`boot()` — только для поздних runtime hooks после сборки registry.

### Migration phase order (`platform:install` / `platform:update`)

```
Phase 1: tenant platform migrations  (database/migrations/tenant/)
Phase 2: feature migrations          (database/migrations/features/{name}/)
Phase 3: solution migrations         (из solution package)
Phase 4: boot validation
Phase 5: activation
```

---

## fapost/foundation — Extension Boundary Contract

Пакет `packages/fapost-foundation` (composer: `fapost/foundation`) — единственный публичный контрактный слой для всех
внешних расширений. Подключается через path repository.

**Назначение:** контракты, DTO, lifecycle-абстракции, manifests. Никакой бизнес-логики и никакого runtime-кода.

**Правило:** новые публичные контракты для расширений создаются только в SDK. Запрещено появление extension-контрактов в
`app/Domains/*`.

**Зависит от:** `php`, `illuminate/contracts`. **Не зависит от Core.** См. раздел "Dependency direction".

**Что живёт в SDK:**

| Файл | Назначение |
|------|-----------|
| `Contracts/CoreRegistrarInterface` | `registerNodeHandler`, `registerDataAccessor`, `registerRagAdapter`, `registerManifest`, `registerRoutes`, `registerSchedule`, `registerMigrations` |
| `Contracts/DataAccessorInterface` | `namespace()`, `get(key, contactId, tenantId)`, `supportedKeys()` — **read-only**, без `set()` |
| `Contracts/NodeHandlerInterface` | `type`, `version`, `supportedVersions`, `execute` |
| `Contracts/RagAdapterInterface` | `provider`, `query` |
| `Contracts/ActivatableInterface` | `getId`, `getVersion`, `getManifest`, `onActivate`, `onDeactivate` |
| `DTO/NodeExecutionContext` | `tenantId`, `contactId`, `sessionId`, `nodeId`, `idempotencyKey`, `platform` |
| `Lifecycle/AbstractSolutionServiceProvider` | base provider для Solution-пакетов |
| `Manifest/SolutionManifest` | `id`, `version`, `requiresPlatform`, `requiresCapabilities` |

**`DataAccessorInterface` — единственный контракт.** `module.*` namespace **read-only**: интерфейс не имеет `set()`,
запись бросает `ReadonlyNamespaceException`. Модули управляют своими данными через собственные сервисы, не через state
writer.

---

## fapost/support — Shared Primitives

Пакет `fapost/support` (composer dependency, отдельный репо) — общие переиспользуемые примитивы для Core, Solutions и
Plugins. Это первое место, куда нужно смотреть при появлении общего кода — и первое, о чём забывают.

**Назначение:** общие DTO, enums, value objects, helpers, traits, базовые exceptions.

**Зависит от:** `php`, `illuminate/support`, `illuminate/contracts`. **Не зависит от Core.** См. раздел "Dependency
direction".

**Не зависит от:** `laravel/framework` целиком, Eloquent ORM (для моделей-расширений есть отдельная история через
`BaseModel` в Core), бизнес-доменов.

### Когда выносить в support — обязательная проверка

При создании любого нового класса задать вопрос: **«Это специфично для одного домена/Feature/Solution или это
переиспользуемый примитив?»**

Кандидаты на support (выносить **сразу**, не "потом"):

- **DTO**, которые могут понадобиться больше чем одному домену или будут передаваться через границу Core ↔ Solution.
- **Enums**, описывающие универсальные понятия (Priority, Direction, Status uniform-наборы и т. п.).
- **Value Objects** без доменной логики Core (Money, PhoneNumber, Coordinates, DateRange).
- **Helpers / utility-функции** общего назначения (нормализация строк, работа с массивами, форматирование дат).
- **Traits / concerns** не привязанные к домену (например, для работы с коллекциями, нормализация input).
- **Базовые exceptions**, от которых будут наследоваться доменные (валидационные, lookup-failure и т. п.).

Кандидаты, **которые не выносить:**

- Eloquent-модели и их трейты, специфичные для Eloquent runtime — это `BaseModel` в Core.
- Контракты для расширений — это Foundation, не Support.
- Любая логика, которой нужны: tenant context, доменные сервисы Core, бизнес-правила Core.
- Класс, используемый ровно в одном месте — пока не появился второй потребитель, это преждевременная абстракция.

### Триггеры для проверки

Прежде чем создавать класс в `app/`, спросить:

1. Может ли это понадобиться Solution/Plugin? → **Support** (если без Core зависимостей) или **Foundation** (если это
   контракт).
2. Появилось ли это уже в двух доменах с похожей логикой? → **Support**, рефакторинг дублирования.
3. Это чистый примитив без доменной семантики? → **Support**.

Если в коде Core встречается `use FAPost\Support\...` — хорошо. Если в Support встречается `use App\...` — это баг,
чинить немедленно.

---

## Multi-tenancy

**Две базы данных:**

- `landlord` — одна на платформу. Содержит: `tenants`, `plans`, `subscriptions`.
- `tenant_{slug}` — отдельная PostgreSQL schema per tenant. Содержит всё остальное.

**Переключение:**

- `TenantContextInterface` — `scoped` в контейнере (новый экземпляр per-request, изолирован от соседних запросов).
- Перед обработкой любого запроса/job — `TenantContext::set()`.
- Octane: `TenantSwitcher::runForTenant(callable)` для изоляции между запросами.
- Все сервисы с per-request состоянием или инжектирующие `TenantContextInterface` — `scoped` (`TenantDatabaseManager`,
  `CoreBootstrap`, `DomainBootstrapper`, `TenantSwitcher`).

**TenantProvisioningService** — единый сервис для self-hosted и SaaS:

```
platform:install   → TenantProvisioningService::provision()
SaaS onboarding    → TenantProvisioningService::provision() + billing, flags
```

**Users — всегда в tenant schema**, без исключений. Одинаково для self-hosted и SaaS.

### Landlord access pattern (domain boundary rule)

Любой домен, которому нужно читать из landlord DB, обязан делать это через контракт в `Tenancy/Contracts/`, не через
прямой `DB::connection('landlord')`.

Прецедент: `WebhookRegistryReaderInterface` → `Tenancy/Infrastructure/EloquentWebhookRegistryReader`.

Прямой `DB::connection('landlord')` разрешён только внутри `Tenancy` домена.

---

## Assistant Domain

**Доменная модель:**

- `Assistant` — верхний бизнес-агрегат. Самостоятельная управляемая единица внутри tenant.
- `Channel` — подчинённая сущность `Assistant`. Transport endpoint, отвечает только за подключение канала.

Один tenant может иметь несколько `Assistant`: Sales, Support, Warehouse.

**`Assistant`:** `name`, `is_active`, `default_flow_id`, `default_language`, `fallback_message`, `settings` (JSONB:
AI/runtime).

**`Channel`:** `assistant_id` (FK), `type` (telegram|whatsapp), `token` (encrypted), `secret_token` (encrypted),
`webhook_public_hash` (UNIQUE), `config` (JSONB), `is_active`.

**Инварианты `Channel`:**

- `webhook_public_hash` генерируется один раз при создании.
- Ротация — только через `ChannelService::rotateWebhookHash()`.
- `webhook_public_hash` не меняется при update токена.

### AssistantContext

```php
interface AssistantContextInterface
{
    public function set(AssistantInterface $assistant): void;
    public function get(): AssistantInterface; // throws AssistantNotResolvedException
    public function isResolved(): bool;
}
```

**Порядок установки:** Webhook → resolve tenant → switch schema → resolve channel → resolve assistant → set
AssistantContext → dispatch job.

**Runtime rule:** Flow запускается **ассистентом**, не каналом.

```
Incoming webhook
  → resolve channel (by public_hash из Redis)
  → resolve assistant (assistant_id из channel payload)
  → run assistant flow
```

`flow_sessions` хранит `tenant_id + assistant_id + contact_id` — полный контекст.

**Access control:** User ↔ Assistants many-to-many (`user_assistants` pivot). Пользователь видит только назначенные ему
ассистенты — влияет на список, assistant panel, выбор в триггерах и рассылках.

---

## Contact Domain

**Базовая модель:** `id`, `tenant_id`, `channel_id`, `channel`, `language` (canonical column), `meta` (JSON: имя,
username), `attributes` (JSON: данные собранные платформенными нодами).

**`contacts.attributes` — НЕ кеш модульных данных.** Только данные собранные платформенными нодами (`input`,
`assign`). Модули не пишут сюда напрямую — для модульных данных есть `DataAccessorInterface`.

**Canonical contact-level поля выносятся из `attributes` если участвуют в hot path резолвинга.** Прецедент: `language`.
Решение принимается по факту использования, не превентивно.

### Сегментация — три концепта

| Концепт | Тип | Управление |
|---------|-----|-----------|
| **Groups** | Статические списки | Ручное |
| **Tags** | Динамические метки | Нода `set_tag` в flow |
| **Segments** | Правила выборки | Вычисляется при запросе |

**Таблицы:**

- `contact_groups` + `contact_group_members` (pivot).
- `contact_tags`: `contact_id`, `tag`, `tagged_by` (`flow_session_id` | `staff_user_id`), `tagged_at`.
- `contact_segments`: `rules` (JSON условий), `cached_count`.

**`set_tag` нода (Core, P1):** `action` (add|remove|toggle), `tags` (массив).

**`ContactSegmentResolver`** вычисляет выборку по rules. Используется Broadcasting при создании списка получателей.

---

## Flow Engine

**Ключевые принципы:**

- Flow = граф нод хранящийся как JSON в `flow_definitions.nodes`.
- Engine детерминирован — никакого недетерминированного кода внутри execution loop.
- Node handler резолвится по `(type, version)` из in-memory `NodeHandlerRegistry`.
- Registry собирается при boot из ServiceProviders — никаких запросов в БД при резолвинге.

### Структура ноды

```json
{
  "id": "uuid",
  "type": "send_message",
  "version": 1,
  "label": "Название для конструктора",
  "config": {},
  "outputs": { "default": { "next": "uuid-next" } }
}
```

**`flow_definitions` хранит два поля:**

- `nodes` — execution data, читает engine.
- `nodes_ui` — `{ node_id: {x, y} }`, читает конструктор, engine игнорирует.

**Шаблонизация в config:** `{{flow.name}}`, `{{system.contact_id}}`, `{{rag.answer}}`, `{{module.hr.department}}`.

### NodeExecutionResult

Handler возвращает `sourceHandle` (не `nextNodeId`). Handlers — graph-unaware. Engine резолвит следующую ноду через edge
lookup по `outputs[sourceHandle].next`.

### State namespaces

Строго namespaced JSON. Нарушение = ошибка валидации при сохранении definition.

| Namespace | Владелец | Описание |
|-----------|----------|----------|
| `system.*` | Engine + явно whitelisted system handlers | started_at, current_node, retry_count, language. |
| `flow.*` | input / assign ноды | Данные текущего диалога. |
| `rag.*` | rag_query нода | Структурированный результат RAG. Умирает с сессией. |
| `module.*` | Модуль через `DataAccessorInterface` | Резолвится лениво, **не пишется** в state JSON. |

Whitelist system handlers ведётся явным списком в `SystemStateNamespacePolicy`. Прецедент: `language_selection` пишет
`system.language`.

### State Key Constants

Все ключи в `flow_sessions.state` объявляются как константы в классах-реестрах. Не строки inline, не магические
значения.

Структура:

- `FlowStateNamespace` — префиксы namespace-ов, архитектурный контракт.
- `SystemStateKeys`, `RagStateKeys` — конкретные ключи, сгруппированные по владельцу.

```php
final class FlowStateNamespace
{
    public const SYSTEM = 'system';
    public const FLOW   = 'flow';
    public const RAG    = 'rag';
    public const MODULE = 'module';
}

final class SystemStateKeys
{
    public const SENT_MESSAGES = FlowStateNamespace::SYSTEM . '.sent_messages';
    public const STARTED_AT    = FlowStateNamespace::SYSTEM . '.started_at';
    public const RETRY_COUNT   = FlowStateNamespace::SYSTEM . '.retry_count';
    public const LANGUAGE      = FlowStateNamespace::SYSTEM . '.language';
    public const DELAY_PREFIX  = FlowStateNamespace::SYSTEM . '.delay'; // + ".{nodeId}.scheduled_at"
}
```

**Правила:**

- Константы с конкатенацией через `FlowStateNamespace::*` (PHP 8.3+ выражения в константах).
- `SystemStateKeys`, `RagStateKeys` — рядом с engine (`app/Domains/Flow/State/`).
- `module.*` ключи — в пакете Solution, не в Core.
- Inline строки `'system.sent_messages'` в handler-ах — запрещены.

### Версионирование нод

- Backward-compatible изменение → version не меняется.
- Breaking change → version++ в новых flow, старый handler остаётся.
- `FlowSession` фиксирует `flow_definition_id` на старте и выполняется по нему до конца.

### Концепт DataAccessor

Flow engine не читает напрямую из модульных таблиц. Только через `DataAccessorInterface` (см. Foundation).

`ConditionNodeHandler` — единственный handler с `DataAccessorRegistryInterface`. Пути `module.*` → registry, остальные →
`data_get($state, $path)`.

`flow_logs` пишет snapshot значений, которые engine читал в момент перехода:

```json
{ "node": "condition", "resolved": { "module.hr.department": "logistics" }, "transition": "node_x" }
```

### Concurrency

Три обязательных уровня защиты в `ProcessIncomingMessageJob`:

1. **Idempotency key:** `Redis SET NX "processed:{update_id}" EX 86400`.
2. **Distributed lock:** `Redis lock "session_lock:{tenant_id}:{contact_id}:{assistant_id}" TTL=30s`.
3. **Optimistic locking:** `flow_sessions.version` — `UPDATE WHERE version = N`.

Если lock не получен — job уходит в backoff очередь, **не дропается**.

**Каждый handler обязан быть safe to retry.** Идемпотентность фиксируется в state самим handler-ом.

### configSchema reference

Полный словарь полей и опций, которые принимает `NodeHandlerInterface::configSchema()` — в [
`docs/builder-config-schema-reference.md`](docs/builder-config-schema-reference.md). Добавил новый field-тип в
renderer → расширил reference. Это контракт, не комментарий.

---

## Node taxonomy

| Тип | Уровень | P |
|-----|---------|---|
| `send_message` | Core | P0 |
| `input` | Core | P0 |
| `condition` (handler `branch`) | Core | P0 |
| `end` | Core | P0 |
| `delay` | Core | P1 |
| `assign` | Core | P1 |
| `call` | Core | P1 |
| `notify_staff` | Core | P1 |
| `set_tag` | Core | P1 |
| `handler` | Core + Solution/Plugin | P1 |
| `go_to_flow` | Core | P2 |
| `emit_event` | Core | P2 |
| `subflow` | Core | P2 |
| `rag_query` | Feature: RAG | P2 |
| `auth_request` | Feature: AccessControl | P3 |
| `comment` | Core (конструктор) | P3 |

**Удалено из taxonomy (май 2026):**
- `switch` — функционал перекрывается `condition`/`branch` через множественные правила в одной ноде. Иконка и color-scheme удалены из builder.
- `set_attribute`, `set_variable` — оба покрываются `assign` (multi-operations). Один writer, один UI.
- `webhook` — переименован в `call` (matches `CallNodeHandler`). Не путать с triggers: `webhook` остаётся как **trigger type** (входящий HTTP запрос для старта flow).

**`send_message` content_type:** `text`, `text_with_keyboard`, `image`, `document`, `video`, `voice`.

**Кнопки:**

- `id` — UUID, хранится в `callback_data`. Стабилен навсегда.
- `type` — `callback` (InlineKeyboard) или `reply` (ReplyKeyboard).
- `value` — бизнес-значение, сохраняется в state при нажатии. **Language-agnostic, не переводится.**
- `order` + `row` — раскладка по рядам.

**Outputs (именованные порты):**

- `default` — стандартный выход.
- `true` / `false` — condition.
- `no_response` — input timeout.
- `error` / `success` — webhook, handler.
- `authenticated` / `failed` — auth_request.
- `no_agents` — assign.
- `[значение]` + `default` — switch.

**Input нода — типизация:**

- `expected_type`: text, number, phone, email, callback, reply, contact, location, document, image, any.
- `validation`: required, pattern, min_length, max_length, custom_handler.
- `on_invalid`: message, retry_limit, on_exceed (node_id).
- Выходы: `default`, `no_response`, `invalid`.

**`auth.phone_button` + `input(expected_type: contact)`** = полный сценарий аутентификации по номеру телефона.

**`handler` нода:** тип ноды живёт в Core, реализации регистрируют Solution/Plugin при boot через `CoreRegistrar`.
`handler_id` определяет реализацию (`hr.sync_employee`, `crm.create_deal`). `TenantActivationRuntime` фильтрует
доступные `handler_id` по активированным Solution/Plugin.

### Handler Registry — namespace taxonomy

| Namespace | Уровень | Примеры |
|-----------|---------|---------|
| `messaging.*` | Core | `messaging.typing` |
| `contact.*` | Core | `contact.add_to_group`, `contact.block`, `contact.update_language` |
| `flow.*` | Core | `flow.restart`, `flow.clear_state` |
| `util.*` | Core | `util.random_branch`, `util.calculate`, `util.format_date`, `util.log` |
| `auth.*` | Feature: AccessControl | `auth.phone_button`, `auth.code_sms` |
| `rag.*` | Feature: RAG | `rag.query`, `rag.index_document` |
| `hr.*` | Solution: HR | `hr.sync_employee`, `hr.create_assessment` |
| `{solution}.*` | Solution | Любой будущий Solution |
| `{plugin}.*` | Plugin | Внешний Plugin |

`HandlerRegistration` содержит: `handler_id`, `namespace`, `owner_level` (core/feature/solution/plugin), `owner_id`,
`handler` instance.

`TenantActivationRuntime` фильтрует доступные handlers по `owner_level` и `owner_id` против активированных
Feature/Solution/Plugin тенанта.

---

## Multilingual Architecture

Зафиксировано: апрель 2026.

### Два языковых слоя (не пересекаются)

- **Admin UI language** — Laravel lang files (`resources/lang/{locale}`). Только панель, backend validation,
  staff-уведомления. К runtime бота отношения не имеет.
- **Content language** — runtime layer для assistants, flow, сообщений пользователям, system bot-level сообщений.

### Tenant language policy

Хранится в `tenant_settings`:

- `content_base_language` — source language всех flow tenant. **Locked после создания первого flow** (UI disabled при
  `flow_definitions count > 0`).
- `available_languages` — список доступных языков.
- `fallback_language` — последний fallback runtime resolver.

`content_base_language` **не участвует** в runtime resolution. Только source для Flow Content Manager.

### Runtime Language Resolution

Единая точка резолвинга: `LanguageResolverInterface`. **Прямые проверки языка в handlers и sender pipeline запрещены.**

```php
interface LanguageResolverInterface
{
    public function resolve(Contact $contact, Assistant $assistant, FlowSession $session): string;
}
```

Цепочка (первый непустой):

```
session.state.system.language
  ?? contact.language
  ?? assistant.default_language
  ?? tenant_settings.fallback_language
```

### Уровни хранения языка

| Уровень | Storage | Кто пишет | Назначение |
|---------|---------|-----------|------------|
| Session | `flow_sessions.state.system.language` | `language_selection` handler | Override внутри сессии |
| Contact | `contacts.language` (canonical, не в attributes) | `contact.update_language` handler | Основной язык user |
| Assistant | `assistants.default_language` | Assistant config | Default для новых contacts |
| Tenant | `tenant_settings.fallback_language` | Tenant settings | Последний fallback |

`contacts.language` — отдельное canonical поле, не JSON. Часто читается, hot path.

### System translations (tenant-level)

Для auth/validation/fallback/default service buttons и любых текстов, которые **бот отправляет конечному пользователю**, не из flow.

**Каталог системных ключей** — `app/Domains/Flow/Translations/` (`SystemTranslationCatalogInterface`, `InMemorySystemTranslationCatalog`). Все известные ключи + дефолты на en/ru/uk регистрируются в коде. Core seed — `CoreSystemTranslations::seed()` в `FlowServiceProvider::boot()`. Features и Solutions регистрируют свои ключи через тот же catalog из своих провайдеров.

**Таблица `tenant_translations`** хранит **только overrides**: `tenant_id`, `key`, `language`, `value`. Если строки нет — резолвер падает в catalog default.

**Resolver chain** (`CachedContentTranslator::translate`):

```
tenant_translations[key, lang]
  → tenant_translations[key, content_base_language]
  → catalog[key, lang]
  → catalog[key, fallback='en']
  → key  (last resort, пишется warning в лог)
```

**Filament UI** — `app/Filament/Resources/TenantTranslations/`. Не CRUD «введите что хотите»: создание override = выбор ключа из каталога + языка + значение. `Reset` (DeleteAction) удаляет override → возврат к catalog default.

**Жёсткое правило при любом изменении кода:** если в Core/Feature/Solution появляется текст, который бот будет отправлять пользователю **не из flow** (busy notice, ack команды, fallback, валидационные сообщения, дефолтные label кнопок) — он **обязательно** регистрируется как `SystemTranslationEntry` в каталоге со всеми платформенными locales (en/ru/uk минимум). Литералы в коде запрещены: вместо них — `$translator->translate('...', $language)`.

Прецеденты, где правило применено: `commands.{reset,cancel}.response`, `errors.{busy,session_expired,fallback,flow_not_found}`, `buttons.{yes,no,confirm,cancel,back}`.

### Flow multilingual content

Локализуемые поля inline в JSON ноды:

```json
{
  "text": { "es": "Bienvenido", "en": "Welcome" },
  "buttons": [
    { "label": { "es": "Confirmar", "en": "Confirm" }, "value": "confirm" }
  ]
}
```

- `value` **никогда не переводится** — только `label`.
- Default generated buttons (yes/no, confirm/cancel, back) → через `tenant_translations`, не через flow JSON.
- Fallback: `localized field → assistant.default_language → tenant.fallback_language`.

### Flow Content Manager

Отдельная вкладка конструктора. **Не translation page** — extracted content manager (включая base language).

- Canvas всегда показывает `content_base_language`.
- Stable key: `node_id + field_path` (`node_12.text`, `node_12.buttons.0.label`).
- Редактируется: text, labels, captions, prompts.
- **Запрещено:** outputs, next links, button order/count/values, condition values, структура ноды.
- Удаление ноды → переводы для неё теряются (ожидаемо).

### Архитектурные правила

- Резолвинг языка — только через `LanguageResolverInterface`.
- `contacts.language` — canonical column, не `attributes` JSON.
- `content_base_language` неизменяем после первого flow.
- System content и flow content имеют **разные fallback chains** — не смешивать.
- `value` в кнопках/выборах — language-agnostic, всегда.

---

## Messaging

### Webhook routing

URL: `/webhook/{channel}/{public_hash}`

`public_hash` → Redis → `{ tenant_id, assistant_id, channel_id, channel, secret_token }`

Landlord не участвует в hot path. Redis — единственный источник для webhook routing.

**Write-through contract:**

- БД — источник истины.
- Redis — hot cache, TTL не ставим.
- `platform:install` прогревает registry: `Channel::active()->with('assistant')->each(fn($c) => $registry->set($c))`.

### Очереди (Horizon)

Жёсткая изоляция, никогда не смешивать:

```
messaging.transactional   — ответы в диалоге, HIGH priority
messaging.broadcast       — рассылки, LOW priority
messaging.system          — служебные уведомления платформы
flow.execution            — обработка входящих
sync.external             — синхронизации из внешних систем
scheduled.triggers        — крон-запуски flow
```

### Rate limiting и backpressure

- Provider rate limit: Redis rate limiter per `(bot_id, chat_id)` — превентивно, не по ошибке от Telegram.
- Broadcast backpressure: `BroadcastSendJob` проверяет длину transactional queue. При насыщении → `release` с delay.
- Broadcast разбивается на N × `BroadcastSendJob` (один job = один получатель) с rate-limited chaining.

### RAG

RAG нода никогда не кладёт raw LLM output в state.
Только через `RagAdapterInterface::query()` → `StructuredRagResult`.
`StructuredRagResult` содержит: `found`, `confidence`, `answer`, `intent?`, `metadata`.

---

## Flow Triggers

Триггер — условие старта flow. Не нода, а внешнее событие.

| Тип | Когда срабатывает |
|-----|-------------------|
| `keyword` | Контакт написал ключевое слово или /start |
| `webhook` | Внешний HTTP запрос от CRM/HR системы |
| `schedule` | Cron-выражение |
| `event` | Внутреннее событие (flow.completed + optional delay) |
| `api` | Программный вызов из Solution/Plugin |

`emit_event` нода (P2) публикует событие из flow → `event` триггер подписывается → chains между flow с опциональной
задержкой.

---

## Broadcasting (Feature)

Управляемая отправка flow/сообщения группе контактов.

**`broadcasts`:** `tenant_id`, `assistant_id`, `name`, `type` (flow|message), `flow_id`, `target_type` (
group|segment|tag|all), `scheduled_at`, `cron`, `status` (draft→scheduled→running→completed), `stats` JSON.

**`broadcast_recipients`:** `contact_id`, `status` (pending|sent|failed|skipped), `sent_at`, `error`.

**Отличие от schedule триггера:** Broadcast — управляемая операция через UI со статусами и аналитикой. Schedule
триггер — автоматический старт без UI.

---

## Логирование и retention

- `flow_logs` — raw, партиционирование по `created_at` (monthly), retention 30 дней. DROP partition — мгновенно.
- `analytics_events` — агрегированные бизнес-события (flow_started, flow_completed, flow_failed, rag_query_made,
  broadcast_sent), хранятся постоянно.

**Не логировать в raw:**

- `delay` node executions (миллионы при рассылке).
- Промежуточные state updates внутри одной сессии.
- Успешные idempotency check hits.

Партиционирование обязательно с первого дня. `DELETE` по retention на тяжёлой таблице убивает PostgreSQL.

---

## Shared Domain — BaseModel Extension Points

`BaseModel` предоставляет три opt-in точки расширения через трейты (все три включены по умолчанию, инертны без
объявления свойства):

| Трейт | Свойство модели | Что делает |
|-------|----------------|------------|
| `HasComputedAttributes` | — | `__get` + `attributesToArray` через `ModelAttributeRegistry` |
| `InteractWithUtilities` | `$utilitiesClass` | `__call` → utility class (implements `ModelUtilityInterface`) |
| `InteractWithBuilder` | `$customBuilder` | `newEloquentBuilder` → кастомный `Builder` |

Все модели могут наследовать `BaseModel` — трейты не влияют на стандартное Eloquent поведение без объявления свойств.

**Solutions расширяют Core модели через `ModelAttributeRegistry::register()`**, не через наследование или добавление
колонок в Core-таблицы.

**Relations остаются в моделях** — `with()` / `whereHas()` / `withCount()` требуют метод прямо на классе. Registry для
relations невозможен без потери Eloquent machinery. Для кросс-доменных relations из Solution —
`Model::resolveRelationUsing()` (встроенный Laravel механизм).

`app()` в трейтах — допустимое исключение: Eloquent создаёт модели напрямую, обходя constructor DI. Изолировано в
трейтах с явным комментарием.

`ModelAttributeRegistry` замораживается в `AppServiceProvider::booted()`. Регистрация после freeze — `LogicException`.
Тесты сбрасывают экземпляр через `app()->forgetInstance()` в `setUp()`.

---

## Frontend Extension Boundary: Plugin vs Solution (ADR-06)

### Таблица возможностей

| Capability                     | Core     | Plugin   | Solution  | SaaS-only |
|--------------------------------|----------|----------|-----------|-----------|
| NodeHandler                    | ✓        | ✓        | ✓         | ✗         |
| DataAccessor                   | ✓        | ✓        | ✓         | ✗         |
| Flow template                  | ✓        | ✓        | ✓         | ✗         |
| Tenant migrations              | ✓        | ✗        | ✓         | ✗         |
| Landlord access                | ✗        | ✗        | ✗         | ✓         |
| Queue jobs                     | ✓        | limited  | ✓         | ✗         |
| Scheduled jobs                 | ✓        | limited  | ✓         | ✗         |
| Custom Vue config component    | ✓        | ✗        | ✓         | ✗         |
| Custom preview renderer        | ✓        | ✗        | ✓         | ✗         |
| Custom field renderer          | ✓        | ✗        | ✓         | ✗         |
| Filament resources             | ✓        | ✗        | ✓         | ✗         |
| Runtime install without deploy | ✗        | ✓        | ✗         | ✗         |
| Composer package               | internal | optional | mandatory | internal  |

**Plugin limited для Queue/Scheduled:** Plugin может диспатчить jobs в существующие очереди платформы, но не
регистрирует новые worker pools и scheduled entries в kernel.

**Flow template у Plugin** — JSON файл, не привязан к build pipeline. Plugin поставляет через `vendor:publish` без
перебилда.

**Config component vs Preview renderer:**

- Config component — правая панель при выборе ноды.
- Preview renderer — карточка ноды в sequence editor (центральная колонка).

### Plugin — только логика, без Vue компонентов

Plugin устанавливается без перебилда frontend — Vue компоненты невозможны. Ноды от Plugin рендерятся через
`SchemaConfigRenderer` (schema-driven) и дефолтный `FlowNodeCard`. Если стандартных field types недостаточно — расширять
`SchemaConfigRenderer` в Core, не делать Plugin Solution-ом.

### Solution — Vue компоненты через vendor:publish (с перебилдом)

Solution публикует компоненты в предсказуемую структуру:

```
vendor/fapost/solution-{name}/
  resources/js/builder/
    SyncEmployeeConfig.vue     ← config panel override
    SyncEmployeePreview.vue    ← node card preview override (опционально)
```

Builder должен подхватывать через Vite glob (⚠️ **спроектировано, но ещё НЕ реализовано** — сейчас `ConfigPanel.vue`
использует хардкод-карту `OVERRIDES`, vendor-glob не подключён; задача: `drafts/builder-renderer/05-vendor-glob.md`):

```js
const vendorConfigs = import.meta.glob(
  '../../vendor/fapost/*/resources/js/builder/*Config.vue',
  { eager: true }
)
const vendorPreviews = import.meta.glob(
  '../../vendor/fapost/*/resources/js/builder/*Preview.vue',
  { eager: true }
)
```

**Соглашение по именованию — обязательно:**
`{PascalCaseType}Config.vue` → тип ноды `snake_case`.
`SyncEmployeeConfig.vue` → `sync_employee`.

### Что НЕ делать

- Не давать Plugin возможность поставлять Vue компоненты.
- Не использовать dynamic runtime import для vendor компонентов (CSP, изоляция).
- Не хардкодить vendor overrides в `ConfigPanel` — только через glob.
- Не давать Plugin/Solution доступ к landlord БД — только SaaS-оболочка.
- Plugin не регистрирует worker pools в Horizon и scheduled jobs в kernel.

`npm run build` обязателен после установки каждого Solution. Зафиксировано как шаг в `platform:update` (Task 25).

---

## Что делать автономно

- Создавать модели, миграции, factories, seeders по готовой схеме.
- Писать unit и feature тесты.
- Генерировать boilerplate по существующим паттернам.
- Создавать Filament resources по существующим моделям.
- Рефакторинг внутри домена без изменения интерфейсов.

## Что требует уточнения перед реализацией

- Любое изменение интерфейсов в `Contracts/` (доменных или Foundation).
- Новые доменные концепции которых нет в этом файле.
- Изменение структуры `flow_definitions.nodes` JSON.
- Добавление новых очередей или изменение приоритетов.
- Любое обращение к landlord БД из кода вне `Tenancy` домена.
- Изменение namespace-ов в `flow_sessions.state`.
- Добавление зависимости в `fapost/foundation` или `fapost/support`.

---

## Текущий этап

Этап 1 — Платформа.

Порядок реализации:

1. Структура проекта, базовые providers.
2. Multi-tenancy (TenantContext, переключение схем, миграции).
3. Flow engine (registry, execution loop, session).
4. Messaging pipeline (webhook → queue → worker → response).
5. Filament admin (tenants, assistants, базовое управление).
6. Перенос текущего клиента как первый tenant.

Детали фаз, спринтов, задач и статусов — в `fapost-plan.docx`.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- filament/filament (FILAMENT) - v5
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/framework (LARAVEL) - v12
- laravel/horizon (HORIZON) - v5
- laravel/octane (OCTANE) - v2
- laravel/prompts (PROMPTS) - v0
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/telescope (TELESCOPE) - v5
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/vue3 (INERTIA_VUE) - v3
- vue (VUE) - v3
- tailwindcss (TAILWINDCSS) - v4

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always declare `declare(strict_types=1);` at the top of every `.php` file.
- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/Pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== octane/core rules ===

# Octane

- Octane boots the application once and reuses it across requests, so singletons persist between requests.
- The Laravel container's `scoped` method may be used as a safe alternative to `singleton`.
- Never inject the container, request, or config repository into a singleton's constructor; use a resolver closure or `bind()` instead:

```php
// Bad
$this->app->singleton(Service::class, fn (Application $app) => new Service($app['request']));

// Good
$this->app->singleton(Service::class, fn () => new Service(fn () => request()));
```

- Never append to static properties, as they accumulate in memory across requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
