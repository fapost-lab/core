# CLAUDE.md — FAPost Core

Контекст для Claude Code. Читай перед каждой задачей.

---

## Что это за проект

FAPost Core (Flow Automation Post) — платформа для построения диалоговых ботов в Telegram и WhatsApp.
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
    Bot/              — боты, webhook management
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

## Tenant-aware execution model

**Принципиальная позиция:** Core всегда работает внутри tenant-контекста. Tenant — базовая координата runtime, не опциональная абстракция.

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

**Self-hosted = один tenant**, создаётся при `platform:install`. Не отдельная архитектурная ветка — частный случай общей модели.

**SaaS = внешний control plane** поверх core. Добавляет: tenant lifecycle, billing, provisioning, feature flags, onboarding. Core об этом не знает.

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
- `TenantContextInterface` — синглтон в контейнере
- Перед обработкой любого запроса/job — вызвать `TenantContext::set()`
- Octane: использовать `runForTenant(callable)` для изоляции между запросами

**Правило:** никакой код платформы не обращается к landlord напрямую кроме `Tenancy` домена.

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

---

## Concurrency

Три обязательных уровня защиты в `ProcessIncomingMessageJob`:

1. **Idempotency key:** `Redis SET NX "processed:{update_id}" EX 86400`
2. **Distributed lock:** `Redis lock "session_lock:{tenant_id}:{contact_id}:{bot_id}" TTL=30`
3. **Optimistic locking:** `flow_sessions.version` — UPDATE WHERE version = N

Если lock не получен — job уходит в backoff очередь, не дропается.

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

`public_hash` → Redis → `{ tenant_id, bot_id, schema_name, secret_token }`

Landlord не участвует в hot path. Redis — единственный источник для webhook routing.

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

**CoreRegistrar** — единственный контракт через который Feature/Solution/Plugin взаимодействуют с runtime. Core автоматически оборачивает routes в tenant + auth + activation middleware.

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
  Tenancy/, Flow/, Messaging/, Contact/, Bot/, Broadcasting/
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

## Текущий этап

Этап 1 — Платформа.

Порядок реализации:
1. Структура проекта, базовые providers
2. Multi-tenancy (TenantContext, переключение схем, миграции)
3. Flow engine (registry, execution loop, session)
4. Messaging pipeline (webhook → queue → worker → response)
5. Filament admin (tenants, bots, базовое управление)
6. Перенос текущего клиента как первый tenant
