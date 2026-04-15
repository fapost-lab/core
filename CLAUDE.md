# CLAUDE.md — FAPost Core

Контекст для Claude Code. Читай перед каждой задачей.

---

## Что это за проект

FAPost Core (Flow Automation Post) — платформа для построения диалоговых ассистентов в Telegram и WhatsApp.
Архитектура: FAPost Core (этот репо) + FAPost HR / FAPost {Niche} (отдельные репо) + FAPost SaaS (отдельный репо).

Сейчас разрабатывается только FAPost Core. Без FAPost SaaS, без модулей.

---

## Стек

- PHP 8.4
- Laravel 12
- Laravel Horizon (очереди)
- Laravel Octane (ускорение)
- PostgreSQL (schema per tenant)
- Redis (кеш, очереди, locks)
- Filament (admin-панель)
- Inertia + Vue (клиентский конструктор, позже)

---

## Структура директорий

```
app/
  Domains/
    Tenancy/          — tenant context, switching, config
    Flow/             — flow engine, node registry, session management
    Messaging/        — channel adapters, incoming/outgoing pipeline
    Contact/          — участники диалогов
    Assistant/        — ассистенты и каналы (transport endpoints)
  Jobs/               — оркестрационные jobs (пересекают домены)
  Http/
    Middleware/       — глобальные middleware (tenant resolve, auth)
  Console/
    Commands/         — artisan команды (platform:update и др.)
  Providers/
    AppServiceProvider.php
    DomainServiceProvider.php   — регистрирует всё из Domains/

database/
  migrations/
    landlord/         — migrations для landlord schema (tenants, plans)
    tenant/           — migrations для tenant schema (платформенные)
```

Структура внутри каждого домена:

```
Domains/{Domain}/
  Models/
  Contracts/          — интерфейсы (source of truth для домена)
  Services/
  Jobs/               — jobs принадлежащие этому домену
  Http/
    Controllers/
    Requests/
  Exceptions/
```

---

## Соглашения по коду

**Общие:**

- Подход близкий к DDD, без фанатизма
- Интерфейсы — везде где это целесообразно, не ради галочки
- Финальные классы (`final class`) по умолчанию, снимать явно если нужно наследование
- Readonly properties где возможно
- Enums вместо констант для фиксированных наборов значений

**Именование:**

- Интерфейсы: `{Name}Interface` (не `I{Name}`, не `{Name}Contract`)
- Services: `{Name}Service` — содержат бизнес-логику
- Jobs: `{Verb}{Entity}Job` — `ProcessIncomingMessageJob`, `ExecuteFlowNodeJob`
- Exceptions: `{Name}Exception` — доменные, не используем базовые Laravel exceptions в доменном коде

**Запрещено:**

- Бизнес-логика в Controllers и Jobs — только оркестрация
- Eloquent в доменных сервисах напрямую — только через Repository
- `app()` и `resolve()` внутри доменного кода — всё через DI
- Хелперы Laravel (`config()`, `cache()`) внутри доменных сервисов — инжектить зависимости явно

**Можно и нужно:**

- Eloquent Models в `Domains/{Domain}/Models/` — это нормально
- Laravel Jobs, Events, Listeners — в соответствующих папках домена
- Facades — только в infrastructure слое (Jobs, Controllers, Providers)

---

## ID Strategy (ADR-03)

- Все PK в tenant-схеме: ULID, хранится в postgresql `uuid` типе
- Трейт: `App\Domains\Shared\Concerns\HasUlidPrimaryKey` (использует HasUlids + переопределяет newUniqueId через Str::
  ulid()->toRfc4122())
- Миграции: $table->uuid('id')->primary() — без default, Laravel генерирует сам
- FK: foreignUuid('..._id')->constrained()->cascadeOnDelete()
- external_id: uuid NULL — отдельная колонка, только где нужна внешняя интеграция, не автоматически
- НЕ трогать: webhook_public_hash (random string), tenant_id в landlord-схеме

## Tenant-aware execution model

**Принципиальная позиция:** Core всегда работает внутри tenant-контекста. Tenant — базовая координата runtime, не
опциональная абстракция.

**Core не знает:**

- Сколько tenant существует
- Есть ли landlord DB
- Используется ли SaaS wrapper

**Запрещено в core:**

- SaaS-логика, landlord DB lookups
- `if (isSaas())` / `if (isSingleTenant())`
- Опциональные tenant проверки — только hard fail если контекст не установлен

```php
// Правильно — бросает исключение если контекст не установлен
$tenant = $this->tenantContext->get();

// Запрещено — никакого fallback
$tenant = $this->tenantContext->get() ?? $this->getDefaultTenant();
```

**Self-hosted = один tenant**, создаётся при `platform:install`. Не отдельная архитектурная ветка — частный случай общей
модели.

**SaaS = внешний control plane** поверх core. Добавляет: tenant lifecycle, billing, provisioning, feature flags,
onboarding. Core об этом не знает.

**Users — всегда в tenant schema**, без исключений. Одинаково для self-hosted и SaaS.

**TenantProvisioningService** — единый сервис для обоих сценариев:

```
platform:install   → TenantProvisioningService::provision()
SaaS onboarding    → TenantProvisioningService::provision() + billing, flags
```

## Multi-tenancy

**Две базы данных:**

- `landlord` — одна на всю платформу. Содержит: tenants, plans, subscriptions.
- `tenant_{slug}` — отдельная PostgreSQL schema per tenant. Содержит всё остальное.

**Как работает переключение:**

- `TenantContextInterface` — `scoped` в контейнере (новый экземпляр per-request, изолирован от соседних запросов)
- Перед обработкой любого запроса/job — вызвать `TenantContext::set()`
- Octane: использовать `runForTenant(callable)` для изоляции между запросами
- Все сервисы с per-request состоянием или инжектирующие `TenantContextInterface` — `scoped` (`TenantDatabaseManager`,
  `CoreBootstrap`, `DomainBootstrapper`, `TenantSwitcher`)

**Правило:** никакой код платформы не обращается к landlord напрямую кроме `Tenancy` домена.

---

## Assistant Domain

**Доменная модель:**

- `Assistant` — верхний бизнес-агрегат. Самостоятельная управляемая единица внутри tenant.
- `Channel` — подчинённая сущность `Assistant`. Transport endpoint — отвечает только за подключение канала.

**Один tenant может иметь несколько `Assistant`. Примеры:** Sales Assistant, Support Assistant, Warehouse Assistant.

**`Assistant` содержит:**

- `name`, `is_active`, `default_flow_id`, `fallback_message`, `settings` (JSONB: AI/runtime настройки)

**`Channel` содержит:**

- `assistant_id` (FK → assistants), `type` (telegram|whatsapp), `token` (encrypted),
  `secret_token` (encrypted), `webhook_public_hash` (UNIQUE), `config` (JSONB), `is_active`

**Инварианты `Channel`:**

- `webhook_public_hash` генерируется один раз при создании
- Ротация — только через `ChannelService::rotateWebhookHash()`
- `webhook_public_hash` не меняется при update токена

**AssistantContext — по аналогии с TenantContext:**

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

**Runtime rule:**

```
Incoming webhook
  → resolve channel (by public_hash из Redis)
  → resolve assistant (assistant_id из channel payload)
  → run assistant flow
```

Flow запускается **ассистентом**, не каналом.

**`flow_sessions` хранит `tenant_id + assistant_id + contact_id`** — полный контекст.

**Access control:** User ↔ Assistants many-to-many (`user_assistants` pivot). Пользователь видит только назначенные ему
ассистенты — влияет на список, assistant panel, выбор в триггерах и рассылках.

---

## Flow Engine

**Ключевые принципы:**

- Flow = граф нод хранящийся как JSON в `flow_definitions.nodes`
- Engine детерминирован — никакого недетерминированного кода внутри execution loop
- Node handler резолвится по паре `(type, version)` из in-memory `NodeHandlerRegistry`
- Registry собирается при boot из ServiceProviders — никаких запросов в БД при резолвинге

**Структура ноды в JSON:**

```json
{
  "id": "uuid",
  "type": "send_message",
  "version": 1,
  "config": {},
  "transitions": []
}
```

**Версионирование:**

- Backward-compatible изменение → version не меняется
- Breaking change → version++ в новых flow, старый handler остаётся зарегистрированным
- `FlowSession` фиксирует `flow_definition_id` на старте и выполняется по нему до конца

**State namespace-ы** (строго, нарушение = ошибка валидации):

- `system.*` — только engine
- `flow.*` — input/set_attribute ноды
- `rag.*` — rag_query нода, умирает с сессией
- `module.*` — модули через DataAccessorInterface

**State Key Constants — соглашение по именованию:**

Все ключи в `flow_sessions.state` объявляются как константы в классах-реестрах.
Не строки inline, не магические значения в handlers.

Структура:

- `FlowStateNamespace` — префиксы namespace-ов, архитектурный контракт
- `SystemStateKeys`, `RagStateKeys` и т.д. — конкретные ключи, сгруппированные по владельцу

```php
// app/Domains/Flow/State/FlowStateNamespace.php
final class FlowStateNamespace
{
    public const SYSTEM = 'system';
    public const FLOW   = 'flow';
    public const RAG    = 'rag';
    public const MODULE = 'module';
}

// app/Domains/Flow/State/SystemStateKeys.php
final class SystemStateKeys
{
    public const SENT_MESSAGES = FlowStateNamespace::SYSTEM . '.sent_messages';
    public const STARTED_AT    = FlowStateNamespace::SYSTEM . '.started_at';
    public const RETRY_COUNT   = FlowStateNamespace::SYSTEM . '.retry_count';
    public const DELAY_PREFIX  = FlowStateNamespace::SYSTEM . '.delay'; // + ".{nodeId}.scheduled_at"
}

// app/Domains/Flow/State/RagStateKeys.php
final class RagStateKeys
{
    public const FOUND      = FlowStateNamespace::RAG . '.found';
    public const CONFIDENCE = FlowStateNamespace::RAG . '.confidence';
    public const ANSWER     = FlowStateNamespace::RAG . '.answer';
    public const INTENT     = FlowStateNamespace::RAG . '.intent';
}
```

**Правила:**

- Константы с конкатенацией через `FlowStateNamespace::*` — PHP 8.3+ поддерживает выражения в константах класса
- `SystemStateKeys`, `RagStateKeys` — рядом с engine (`app/Domains/Flow/State/`)
- `module.*` ключи — в пакете Solution, не в Core
- Inline строки типа `'system.sent_messages'` в handler-ах — запрещены

---

## Concurrency

Три обязательных уровня защиты в `ProcessIncomingMessageJob`:

1. **Idempotency key:** `Redis SET NX "processed:{update_id}" EX 86400`
2. **Distributed lock:** `Redis lock "session_lock:{tenant_id}:{contact_id}:{assistant_id}" TTL=30`
3. **Optimistic locking:** `flow_sessions.version` — UPDATE WHERE version = N

Если lock не получен — job уходит в backoff очередь, не дропается.

**Реализация (задача 12):**

- `FlowExecutionGuard` (`Infrastructure\Flow\`) — единственная реализация distributed lock. Зависит от `LockProvider`, не от `Cache\Repository`. Lock снимается в `finally` без исключений.
- `FlowExecutionGuardInterface` — `App\Domains\Flow\Contracts\`. **Не в Foundation SDK** — это внутренний контракт платформы, не extension point.
- `SessionLockTimeoutException` — `App\Domains\Flow\Exceptions\`. Доменное исключение, не инфраструктурное.
- `FlowOrchestrator` содержит optimistic retry loop (до 3 попыток, `usleep` 50–150ms) **внутри** distributed lock. Сессия перечитывается из БД на каждой попытке.
- `IncomingMessageJob` при `SessionLockTimeoutException`: `release($delay)` + `return`, без `backoff()` метода. Задержки: 1/2/5/10s по номеру попытки. `$tries = 5`.
- `AssistantFlowConfigRepositoryInterface` — `App\Domains\Assistant\Contracts\`. Реализация в `App\Domains\Assistant\Repositories\`. Биндинг в `AssistantServiceProvider`.

---

## Built-in Node Handlers (задача 13)

**Файловая структура:** `app/Domains/Flow/Handlers/{Handler}.php`, support-классы в `Handlers/Support/`.

**Инварианты handlers (жёсткие):**

- Handler только возвращает `NodeExecutionResult` — никогда не вызывает `save()`, не открывает `DB::transaction()`, не диспатчит jobs
- Effects — декларативные намерения: `[['type' => 'set_contact_attribute', 'key' => ..., 'value' => ...]]`
- Engine интерпретирует effects через `ContactServiceInterface::updateAttributes()` внутри своей транзакции

**Идемпотентность:**

- `send_message` — через `system.sent_messages.{nodeId}` в state
- `delay` — через `system.delay.{nodeId}.scheduled_at` в state
- `input`, `condition`, `set_attribute`, `webhook` — детерминированы по входным данным

**`ConditionNodeHandler`** — единственный handler с `DataAccessorRegistryInterface`. Пути `module.*` → registry, остальные → `data_get($state, $path)`.

**`WebhookNodeHandler`** — Transport error (`ConnectionException`) → `Failed`; HTTP response получен (любой код) → `Executed`. Retry-безопасность на стороне получателя через `X-Idempotency-Key: {sessionId}:{nodeId}`.

**`TemplateResolver`** (`Handlers/Support/`) — резолвит `{{flow.*}}` и `{{system.*}}` из state. `module.*` — расширение в задаче 15.

**`FlowEngine` после задачи 13:**

- Все зависимости через интерфейсы: `FlowSessionRepositoryInterface::create()`, `FlowDefinitionRepositoryInterface::findById()`, `ContactServiceInterface::findById()`
- Нет прямых `Model::query()` и `->save()` внутри сервиса

---

## Очереди (Horizon)

Жёсткая изоляция, никогда не смешивать:

```
messaging.transactional   — ответы в диалоге, HIGH priority
messaging.broadcast       — рассылки, LOW priority
flow.execution            — обработка входящих
sync.external             — синхронизации из внешних систем
scheduled.triggers        — крон-запуски flow
```

`messaging.broadcast` workers проверяют насыщение `messaging.transactional` перед отправкой.

---

## Webhook routing

URL: `/webhook/{channel}/{public_hash}`

`public_hash` → Redis → `{ tenant_id, assistant_id, channel_id, channel, secret_token }`

Landlord не участвует в hot path. Redis — единственный источник для webhook routing.

**Write-through contract:**

- БД — источник истины
- Redis — hot cache, TTL не ставим
- `platform:install` прогревает registry: `Channel::active()->with('assistant')->each(fn($c) => $registry->set($c))`

**Landlord DB access pattern (domain boundary rule):**

Любой домен, которому нужно читать из landlord DB, обязан делать это через контракт в `Tenancy/Contracts/`, а не через прямой `DB::connection('landlord')`.
Прецедент: `WebhookRegistryReaderInterface` → `Tenancy/Infrastructure/EloquentWebhookRegistryReader`.
Прямой вызов `DB::connection('landlord')` разрешён только внутри `Tenancy` домена.

---

## RAG

RAG нода никогда не кладёт raw LLM output в state.
Только через `RagAdapterInterface::query()` → `StructuredRagResult`.
`StructuredRagResult` содержит: `found`, `confidence`, `answer`, `intent?`, `metadata`.

---

## Логирование и retention

- `flow_logs` — raw, партиционирование по `created_at` (monthly), retention 30 дней
- `analytics_events` — агрегированные бизнес-события, хранятся постоянно
- Delay nodes, idempotency hits — не логировать в raw

---

## Flow Triggers

Триггер — условие старта flow. Не нода, а внешнее событие.

| Тип | Когда срабатывает |
|-----|-----------------|
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

**broadcasts:** tenant_id, assistant_id, name, type (flow|message), flow_id, target_type (group|segment|tag|all),
scheduled_at, cron, status (draft→scheduled→running→completed), stats JSON.

**broadcast_recipients:** contact_id, status (pending|sent|failed|skipped), sent_at, error.

**Отличие от schedule триггера:** Broadcast — управляемая операция через UI со статусами и аналитикой. Schedule
триггер — автоматический старт без UI.

---

## Contact Domain — сегментация

Три концепта:

| Концепт | Тип | Управление |
|---------|-----|-----------|
| Groups | Статические списки | Ручное |
| Tags | Динамические метки | Нода set_tag в flow |
| Segments | Правила выборки | Вычисляется при запросе |

**set_tag нода (Core, P1):** action (add|remove|toggle), tags (массив).

**contact_tags:** contact_id, tag, tagged_by (flow_session_id | staff_user_id), tagged_at.

**contact_segments:** rules (JSON с условиями), cached_count.

**ContactSegmentResolver** вычисляет выборку по rules. Используется Broadcasting при создании списка получателей.

**Broadcast target_type:** group | segment | tag | all.

## Boot Lifecycle

**Порядок boot pipeline — строгий:**

```
1. Core boot     → AppServiceProvider, DomainServiceProvider
                   contracts, bindings, registries, infrastructure
2. Feature boot  → FeatureServiceProvider
                   читает config/features.php
                   регистрирует все installed Features в ActivationRegistry
3. Solution boot → SolutionServiceProvider
                   обнаруживает composer packages с SolutionInterface
                   регистрирует в ActivationRegistry
4. Plugin boot   → PluginServiceProvider
                   регистрирует в PluginRegistry (отдельный от ActivationRegistry)
5. Tenant runtime → TenantActivationRuntime
                   ТОЛЬКО после установки tenant context
                   ActivationRegistry + tenant_activations DB → active surface
```

**register() — только декларативно. Запрещено:**

- side effects
- обращения к БД
- tenant-specific логика
- прямые вызовы Route/Scheduler facades

**boot() — только для поздних runtime hooks после сборки registry.**

---

## Runtime Surface Governance (Platform Law)

**Никто кроме Core не имеет права напрямую мутировать global runtime.**

| Запрещено | Правильно |
|-----------|-----------|
| `Route::get(...)` напрямую | `$registrar->routes(fn(Router $r) => ...)` |
| `Schedule::call(...)` напрямую | `$registrar->schedule(...)` |
| `Artisan::call('migrate')` напрямую | `$registrar->migrations(__DIR__ . '/Database/Migrations')` |
| `app()->bind(...)` глобально | Только через DomainServiceProvider |

**CoreRegistrar** — единственный контракт через который Feature/Solution/Plugin взаимодействуют с runtime. Core
автоматически оборачивает routes в tenant + auth + activation middleware.

**Migration phase order (platform:install / platform:update):**

```
Phase 1: tenant platform migrations   (database/migrations/tenant/)
Phase 2: feature migrations           (database/migrations/features/{name}/)
Phase 3: solution migrations          (из solution package)
Phase 4: boot validation
Phase 5: activation
```

**Governance модель:**

```
Domain    → владеет infrastructure surface
Feature   → декларирует capability surface
Solution  → декларирует product surface
Plugin    → декларирует extension surface
Core      → собирает final runtime из всех деклараций
```

## Domain, Feature, Solution, Plugin

Четыре понятия с разной ответственностью. Смешивать — архитектурная ошибка.

**Domain** — технический bounded context, всегда активен:

```
app/Domains/
  Tenancy/, Flow/, Messaging/, Contact/, Assistant/, Broadcasting/
```

**Feature** — built-in capability Core, активируется per tenant:

```
app/Features/
  Rag/, AccessControl/, Analytics/
  каждая: Contracts/, Services/, Http/

resources/js/features/
  rag/, analytics/, access-control/   ← Vite видит как монолит

database/migrations/features/
  rag/, analytics/                    ← изолированы от platform
```

**Solution** — готовое нишевое решение, ВСЕГДА внешний composer package:

```
fapost/solution-hr          → implements SolutionInterface
fapost/solution-education   → implements SolutionInterface
```

`app/Solutions/` — НЕ СУЩЕСТВУЕТ в Core.

**Plugin** — ecosystem extension от сторонних разработчиков:

```
fapost/plugin-{name}   → реализует публичные extension points Core
```

---

**Единая модель активации для Feature и Solution:**

```php
interface ActivatableInterface
{
    public function getId(): string;       // hardcoded: 'rag', 'hr'
    public function getVersion(): string;
    public function register(CoreRegistrar $registrar): void;
    public function boot(): void;
}
```

```
ActivationRegistry       — global, in-memory, все установленные
tenant_activations (DB)  — tenant_id, activatable_id, type, is_active
TenantActivationRuntime  — per request/job, что активно для tenant
```

Активация:

- Self-hosted: `config/features.php`, `config/solutions.php`
- SaaS: billing plan → `tenant_activations`

**Ownership boundary: Feature vs Solution:**

- Feature owns contract (напр. `KnowledgeQueryInterface`)
- Solution может предоставить свою реализацию через тот же contract
- Если capability нужна только внутри одного Solution → internal solution service, НЕ global Feature

**Solution versioning — identity сильнее version:**

- Backward compatible change → обычный semver, тот же solution ID
- Breaking change → новый solution identity (`hr` vs `hr_v2`), не upgrade
- Tenant явно мигрирует на новый identity

**Schema lifecycle независим от activation:**

- `tenant_activations.is_active = false` → capability скрыта из runtime
- Таблицы Feature/Solution в tenant schema НЕ удаляются
- Удаление schema — только явная administrative операция, никогда автоматически

**Installed ≠ Enabled** — одинаково для Feature и Solution.

**TenantActivationRuntime влияет на:** routes, flows, screens, menu, API surface, execution rules.

---

**Структура директорий Core (финальная):**

```
app/
  Domains/          — технические bounded contexts
  Features/         — built-in capabilities
  Http/Middleware/  — глобальные middleware
  Console/Commands/ — platform:install, feature:activate, solution:activate
  Jobs/             — оркестрационные
  Providers/        — App, Domain, Feature ServiceProviders

resources/js/
  features/         — Vue компоненты per feature

database/migrations/
  landlord/         — landlord schema
  tenant/           — platform tenant migrations
  features/         — per feature migrations
```

## Handler Registry

**Handler namespace taxonomy:**

| Namespace | Уровень | Примеры |
|-----------|---------|---------|
| `messaging.*` | Core | `messaging.typing` |
| `contact.*` | Core | `contact.add_to_group`, `contact.block` |
| `flow.*` | Core | `flow.restart`, `flow.clear_state` |
| `util.*` | Core | `util.random_branch`, `util.calculate`, `util.format_date`, `util.log` |
| `auth.*` | Feature: AccessControl | `auth.phone_button`, `auth.code_sms` |
| `rag.*` | Feature: RAG | `rag.query`, `rag.index_document` |
| `hr.*` | Solution: HR | `hr.sync_employee`, `hr.create_assessment` |
| `{solution}.*` | Solution | Любой будущий Solution |
| `{plugin}.*` | Plugin | Внешний Plugin |

**HandlerRegistration содержит:** `handler_id`, `namespace`, `owner_level` (core/feature/solution/plugin), `owner_id`,
`handler` instance.

**TenantActivationRuntime фильтрует** доступные handlers по owner_level и owner_id против активированных
Feature/Solution/Plugin тенанта.

**Input нода — типизация:**

- `expected_type`: text, number, phone, email, callback, reply, contact, location, document, image, any
- `validation`: required, pattern, min_length, max_length, custom_handler
- `on_invalid`: message, retry_limit, on_exceed (node_id)
- Выходы: `default`, `no_response`, `invalid`

**auth.phone_button + input(expected_type: contact)** = полный сценарий аутентификации по номеру телефона.

## Node Schema

**Базовая структура:**

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

**flow_definitions хранит два поля:**

- `nodes` — execution data, читает engine
- `nodes_ui` — `{ node_id: {x, y} }`, читает конструктор, engine игнорирует

**Шаблонизация в config:** `{{flow.name}}`, `{{system.contact_id}}`, `{{rag.answer}}`, `{{module.hr.department}}`

**Node Taxonomy:**

| Тип | Уровень | P |
|-----|---------|---|
| `send_message` | Core | P0 |
| `input` | Core | P0 |
| `condition` | Core | P0 |
| `switch` | Core | P0 |
| `end` | Core | P0 |
| `delay` | Core | P1 |
| `set_attribute` | Core | P1 |
| `set_variable` | Core | P1 |
| `webhook` | Core | P1 |
| `notify_staff` | Core | P1 |
| `assign` | Core | P1 |
| `handler` | Core + Solution/Plugin | P1 |
| `go_to_flow` | Core | P2 |
| `rag_query` | Feature: RAG | P2 |
| `auth_request` | Feature: AccessControl | P3 |
| `comment` | Core (конструктор) | P3 |

**send_message content_type:** `text`, `text_with_keyboard`, `image`, `document`, `video`, `voice`

**Кнопки:**

- `id` — UUID, хранится в `callback_data`. Стабилен навсегда.
- `type` — `callback` (InlineKeyboard) или `reply` (ReplyKeyboard)
- `value` — бизнес-значение, сохраняется в state при нажатии
- `order` + `row` — раскладка по рядам, поддержка сортировки

**Outputs (именованные порты):**

- `default` — стандартный выход
- `true` / `false` — condition
- `no_response` — input timeout
- `error` / `success` — webhook, handler
- `authenticated` / `failed` — auth_request
- `no_agents` — assign
- `[значение]` + `default` — switch

**handler нода:**

- Тип ноды живёт в Core
- Реализации регистрируют Solution/Plugin при boot через `CoreRegistrar`
- `handler_id` определяет реализацию: `hr.sync_employee`, `crm.create_deal`
- TenantActivationRuntime фильтрует доступные handler_id по активированным Solution/Plugin

## Shared Domain — BaseModel Extension Points

`BaseModel` предоставляет три opt-in точки расширения через трейты (все три включены по умолчанию, инертны без объявления свойства):

| Трейт | Свойство модели | Что делает |
|-------|----------------|------------|
| `HasComputedAttributes` | — | `__get` + `attributesToArray` через `ModelAttributeRegistry` |
| `InteractWithUtilities` | `$utilitiesClass` | `__call` → utility class (implements `ModelUtilityInterface`) |
| `InteractWithBuilder` | `$customBuilder` | `newEloquentBuilder` → кастомный `Builder` |

**Все модели могут наследовать `BaseModel`** — трейты не влияют на стандартное Eloquent поведение без объявления свойств.

**Solutions расширяют Core модели через `ModelAttributeRegistry::register()`**, а не через наследование или добавление своих колонок в Core-таблицы.

**Relations остаются в моделях** — `with()` / `whereHas()` / `withCount()` требуют метод прямо на классе. Registry для relations невозможен без потери Eloquent machinery. Для кросс-доменных relations из Solution — `Model::resolveRelationUsing()` (встроенный Laravel механизм).

`app()` в трейтах — допустимое исключение: Eloquent создаёт модели напрямую, обходя constructor DI. Изолировано в трейтах с явным комментарием.

**`ModelAttributeRegistry` замораживается** в `AppServiceProvider::booted()`. Регистрация после freeze — `LogicException`. Тесты сбрасывают экземпляр через `app()->forgetInstance()` в `setUp()`.

---

## fapost/foundation — Extension Boundary Contract

Пакет `packages/fapost-foundation` (composer: `fapost/foundation`) — единственный публичный контрактный слой для всех внешних расширений. Подключается через path repository.

**Правило:** новые публичные контракты для расширений создаются только в SDK. Запрещено появление extension-контрактов в `app/Domains/*`.

**Что живёт в SDK:**

| Файл | Назначение |
|------|-----------|
| `Contracts/CoreRegistrarInterface` | registerNodeHandler, registerDataAccessor, registerRagAdapter, registerManifest, **registerRoutes, registerSchedule, registerMigrations** |
| `Contracts/DataAccessorInterface` | `namespace()`, `get(key, contactId, tenantId)`, `supportedKeys()` — **read-only**, без `set()` |
| `Contracts/NodeHandlerInterface` | type, version, supportedVersions, execute |
| `Contracts/RagAdapterInterface` | provider, query |
| `Contracts/ActivatableInterface` | getId, getVersion, getManifest, onActivate, onDeactivate |
| `DTO/NodeExecutionContext` | tenantId, contactId, sessionId, nodeId, idempotencyKey, platform |
| `Lifecycle/AbstractSolutionServiceProvider` | base provider для Solution-пакетов |
| `Manifest/SolutionManifest` | id, version, requiresPlatform, requiresCapabilities |

**`DataAccessorInterface` — единственный контракт.** Дубликат `App\Domains\Shared\Contracts\DataAccessorInterface` удалён. `ModuleNamespaceRegistry` использует Foundation-версию.

**`module.*` namespace — read-only.** `DataAccessorInterface` не имеет `set()`. Запись в module-namespace всегда бросает `ReadonlyNamespaceException`. Модули управляют своими данными через собственные сервисы, не через state writer.

**`ModuleResolutionContext`** (`app/Domains/Flow/State/Resolvers/ModuleResolutionContext.php`) — scoped-зависимость, несёт `contactId` + `tenantId` для DataAccessor-вызовов. Устанавливается engine'ом перед выполнением ноды. Инжектируется в `ModuleStateResolver` через конструктор.

**Octane note:** `NamespaceResolverRegistry` — singleton, `ModuleResolutionContext` — scoped. Безопасно пока flow execution идёт через queue workers (FPM). Если scope расширится на Octane — нужен lifecycle audit.

**`loadSolutionMigrations()` deprecated.** Использовать `CoreRegistrar::registerMigrations()` когда будет реализован в задаче 21. До тех пор метод бросает `E_USER_DEPRECATED`.

---

## Что делать автономно

- Создавать модели, миграции, factories, seeders по готовой схеме
- Писать unit и feature тесты
- Генерировать boilerplate по существующим паттернам в проекте
- Создавать Filament resources по существующим моделям
- Рефакторинг внутри домена без изменения интерфейсов

## Что требует уточнения перед реализацией

- Любое изменение интерфейсов в `Contracts/`
- Новые доменные концепции которых нет в этом файле
- Изменение структуры `flow_definitions.nodes` JSON
- Добавление новых очередей или изменение приоритетов
- Любое обращение к landlord БД из кода вне `Tenancy` домена
- Изменение namespace-ов в `flow_sessions.state`

---

## Octane — ADR-01: ingress-only

**Решение принято: март 2026.**

Octane используется **только для webhook ingress** (`/webhooks/*`). Основное приложение работает на PHP-FPM.

**Причина:** система содержит mutable scoped context (`TenantContext`, `CurrentAssistant`). Полный Octane runtime
создаёт риск state leakage при нарушении scoped lifecycle.

**Модель деплоя:**

| Компонент | Runtime | Обслуживает |
|---|---|---|
| Main app | PHP-FPM | `/admin`, `/assistant`, API |
| Webhook ingress | Octane (RoadRunner) | `/webhooks/*` |

Routing split на уровне Traefik / Nginx: `/webhooks/*` → octane, всё остальное → php-fpm.

**Обязательные ограничения для Octane ingress:**

- Весь webhook path stateless — нет накопленного состояния между запросами
- Любой tenant switch — только через `TenantSwitcher::runForTenant()` с `finally restore()`
- Нет mutable singleton state — только scoped bindings
- Нет session, нет Filament, нет navigation state в webhook path

**Намеренно исключено из Octane:** Filament panels, assistant panel, admin panel, обычные tenant routes.

**Расширение Octane scope** допустимо только после полного lifecycle audit всех scoped bindings.

---

## Текущий этап

Этап 1 — Платформа.

Порядок реализации:

1. Структура проекта, базовые providers
2. Multi-tenancy (TenantContext, переключение схем, миграции)
3. Flow engine (registry, execution loop, session)
4. Messaging pipeline (webhook → queue → worker → response)
5. Filament admin (tenants, assistants, базовое управление)
6. Перенос текущего клиента как первый tenant

# Implementation Plan

**Документ:** `fapost-plan.docx` — полное описание фаз, спринтов, задач и архитектурных контрактов.
**Версия плана:** 2.0 · Март 2026 (пересмотрен после архитектурного ревью).
**MVP:** достигается после Фазы 2 (задача 16).

---

## Архитектурные контракты (жёсткие, без исключений)

### Migration Isolation Contract

Миграция — это чистая DDL-операция. Она не знает о тенантах, конфигах, модулях и состоянии системы.

**Запрещено внутри `up()`/`down()`:**

- `app()`, `config()`, `env()` — кроме connection name как константы
- `TenantContext::get()` или любой tenant-aware сервис
- Условные ветки на основе module activation или feature flags
- Seed-данные зависящие от runtime состояния
- `DB::table()` поверх таблиц платформы или другого модуля (из миграции модуля)

**Enforcement:** phpat-правило в CI, начиная с задачи 03.

### Handler Version Contract

- Каждый `NodeHandler` реализует `NodeHandlerInterface`: `type()`, `version()`, `supportedVersions()`
- Engine резолвит handler по `(type, version)` из in-memory registry — без запросов в БД
- Backward-compatible изменение → version не меняется
- Breaking change → version++ в новых flow, старый handler остаётся зарегистрированным
- **Безопасное удаление handler** = `flow_active_node_stats` показывает 0 И нет `flow_sessions` со статусом `waiting`/
  `paused` с этим `type@version`
- **Enforcement:** phpat-правило

### Module Registration Contract

- `ModuleRegistrarInterface` — заглушка с задачи 04. Полная реализация только в Фазе 4.
- Модуль декларирует intent через `CoreRegistrar` — никогда не вызывает `Route`, `Schedule`, `Migrations` напрямую
- `DataAccessorInterface`, `RagAdapterInterface` — module-grade extension points, контракты фиксируются в Фазах 0–2
- Solutions живут только как внешние composer-пакеты, никогда в `app/Solutions/`

### UI Non-interference Contract

- Filament UI читает только из `analytics_events` и существующих query scopes
- Новые методы на доменных объектах ради UI — запрещены до стабилизации runtime model
- Задача 20 — строго read-only surface

---

## Фазы и спринты

### Фаза 0 — Фундамент *(Спринты 1–2)*

Разбита на два спринта. `platform:install` появляется только в спринте 2 — когда provisioning контракт стабилен.

**Спринт 1 — контракты и инфраструктура**

| # | Название | Статус |
|---|----------|--------|
| 01 | Project scaffolding (Laravel 12 без Octane, Horizon, Filament, Inertia+Vue, phpat, migration path structure) | ✅ |
| 02 | Tenancy Domain (контракты, Tenant модель, TenantSettings, миграция landlord.tenants) | ✅ |
| 03 | Tenant infrastructure (TenantRepository, TenantDatabaseManager, Redis webhook registry write, **Migration Isolation Contract как phpat-правило**) | ✅ |
| 4.1 | Model Extension Infrastructure (`BaseModel` + `ModelAttributeRegistry`) | ✅ |
| 4.2 | Introduce `fapost/foundation` — публичный контрактный пакет для extension boundary | ✅ |

**Спринт 2 — boot lifecycle**

| # | Название | Статус |
|---|----------|--------|
| 04 | Tenancy middleware & boot (TenancyMiddleware, CoreBootstrap, DomainServiceProvider chain, platform:install, **ModuleRegistrarInterface заглушка**) | ✅ |

---

### Фаза 1 — Core Platform *(Спринт 3)*

Octane вводится последним в фазе — когда все lifecycle boundaries известны.

| # | Название | Статус |
|---|----------|--------|
| 05 | Staff Domain (users, roles, Filament Shield, Filament panel) | ✅ |
| 06 | ~~Bot Domain~~ → **superseded by 06b** | — |
| 06b | Assistant & Channel Domain (модели `Assistant`+`Channel`, миграции `assistants`+`channels`, `AssistantService`+`ChannelService`, Redis registry write с `assistant_id`+`channel_id`, `ChannelWebhookRegistry`) | ✅ |
| 07 | Contact Domain (модель, channel identity, contact_groups, findOrCreate) | ✅ |
| 08 | Webhook routing (ChannelAdapter Telegram+WA, signature verify, idempotency Redis SET NX, tenant resolve, resolve assistant через channel, IncomingMessageJob) | ✅ |
| 08a | Octane integration — **ingress-only scope** (RoadRunner config для `/webhooks/*`, routing split Traefik/Nginx, stateless webhook path validation) — ADR-01 | ✅ |

---

### Фаза 2 — Flow Engine *(Спринты 4–6)* — MVP

**Спринт 4 — ядро движка**

| # | Название |
|---|----------|
| 09 | Flow definition & registry (flow_definitions, **NodeHandlerInterface**, NodeHandlerRegistry in-memory, **HandlerVersionContract phpat**, flow_active_node_stats, правило безопасного удаления) | ✅ |
| 10 | Flow session & state (flow_sessions с `assistant_id`, namespaced state system/flow/rag/module, optimistic lock, FlowState VO, namespace violation = validation error) | ✅ |

**Спринт 5 — execution & concurrency**

| # | Название |
|---|----------|
| 11 | Flow execution engine (FlowEngine::start/resume, execute loop, dispatch по (type,version), session persist) |
| 12 | Concurrency protection (distributed lock `session_lock:{tenant_id}:{contact_id}:{assistant_id}` Redis TTL=30s, optimistic lock retry, backoff при lock miss — не дроп) | ✅ |
| 13 | Built-in node handlers (send_message, input, condition, delay, set_attribute, webhook — все idempotent) | ✅ |
| 14 | Flow triggers (flow_triggers, TriggerResolver, IncomingMessageJob → FlowEngine) |

**Спринт 6 — data access & logging**

| # | Название |
|---|----------|
| 15 | DataAccessor layer (DataAccessorInterface, DataAccessorRegistry, condition нода только через accessor, flow_logs snapshot) |
| 16 | Logging & retention (flow_logs monthly partition, analytics_events forever, retention 30d) |

---

### Фаза 3 — Messaging Pipeline *(Спринт 7)*

| # | Название |
|---|----------|
| 17 | Message queues & sender (transactional HIGH / broadcast LOW / system, worker pools Horizon) |
| 18 | Broadcast engine (BroadcastSendJob 1:1, rate limiter Redis, backpressure check) |
| 19 | RAG adapter (RagAdapterInterface, StructuredRagResult, knowledge_bases, rag_query нода) |
| 20 | Filament admin UI (Assistants, Contacts, Flow list, analytics dashboard — **только read-only**) |

---

### Фаза 4 — Module System & HR *(Спринты 8–9)*

Контракты `ModuleRegistrarInterface`, `DataAccessorInterface`, `RagAdapterInterface` уже существуют с Фаз 0–2. Здесь —
полная реализация.

**Спринт 8 — module framework**

| # | Название |
|---|----------|
| 21 | Module framework (ModuleManifest, ActivatableInterface, CoreRegistrar полная реализация, capabilities validation, hard fail) |
| 22 | Module isolation (degraded state, session failed + fallback, активные сессии доживают по snapshot) |

**Спринт 9 — HR-модуль (fapost/solution-hr)**

| # | Название |
|---|----------|
| 23 | HR Domain (employees canonical, employee_sync_logs, HrDataAccessor, sync_employee нода, scheduled sync) |
| 24 | HR assessments (assessments 360°, assessment_results, access_control_rules, flow templates, Filament HR) |

---

### Фаза 5 — Self-hosted ops & SaaS shell *(Спринт 10)*

| # | Название |
|---|----------|
| 25 | platform:update (5 фаз: VALIDATE→MIGRATE platform→MIGRATE modules→BOOT VALIDATION→ACTIVATE, rollback per phase) |
| 26 | SaaS shell (plans, subscriptions внутренний учёт, feature_flags, tenant onboarding, landlord panel) |
| 27 | Flow constructor UI (Vue Flow граф-редактор, Inertia, drag-and-drop, flow_definition snapshot, activate) |

---

## Решённые вопросы

| Вопрос | Решение |
|--------|---------|
| Flow constructor | Vue Flow |
| Billing / Stripe | Недоступен юридически — внутренний учёт |
| Octane в scaffolding | Отложен до конца Фазы 1 (задача 08a) |
| Фаза 0 перегружена | Разбита на два спринта: контракты отдельно от boot lifecycle |
| Migration discipline | Migration Isolation Contract + phpat с задачи 03 |
| Module contracts слишком поздно | ModuleRegistrarInterface заглушка с задачи 04 |
| UI диктует backend | UI Non-interference Contract, задача 20 строго read-only |
| Bot как верхний домен | **Упразднён.** `Assistant` — верхний бизнес-агрегат, `Channel` — transport. Задача 06 superseded by 06b. |
| Octane scope | **ADR-01: ingress-only.** Octane только для `/webhooks/*`. Main app (`/admin`, `/assistant`, API) — PHP-FPM. Причина: mutable scoped context (`TenantContext`, `CurrentAssistant`). |
| Assistant panel архитектура | **ADR-02: separate operational console.** Отдельный Filament panel provider `/assistant`. Статичный prefix, identity через `?assistant={uuid}` (query param — приоритет, сессия — fallback). `CurrentAssistant` scoped. Нет дублирования бизнес-логики — только через `AssistantService`/`ChannelService`. |

## Открытые вопросы

| Вопрос | Когда |
|--------|-------|
| RAG провайдер: OpenAI Assistants vs pgvector | До Фазы 3, задача 19 |
| Enterprise-клиент: legacy или новый тенант? | До Фазы 1 |
| Backpressure threshold: per tenant или глобальный? | Задача 18 |
| Cross-module migration enforcement: только phpat или + runtime check? | Задача 25 |
| Миграции при отключении модуля: остаются или soft-delete? | Задача 22 |
| Единый manifest contract: адаптация SaaS vs self-hosted | Задача 25 |
| Второй Solution после HR | После Фазы 4, по обратной связи рынка |

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- filament/filament (FILAMENT) - v5
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
- Follow existing application Enum naming conventions.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

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

</laravel-boost-guidelines>
