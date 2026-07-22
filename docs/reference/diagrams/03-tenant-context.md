# Tenant Context — установка контекста и переключение схемы

Два пути инициализации tenant-контекста: HTTP webhook запрос (через Octane) и Queue Job (через Horizon worker).

```mermaid
sequenceDiagram
    participant TG as Telegram API
    participant OC as Octane Worker
    participant TC as TenantContext<br/>(scoped)
    participant TS as TenantSwitcher
    participant DB as PostgreSQL<br/>tenant_{slug}
    participant Q as Queue<br/>flow.execution
    participant JW as Horizon Worker
    participant TC2 as TenantContext<br/>(scoped, новый экземпляр)

    Note over TG,DB: Путь 1 — HTTP Webhook Request

    TG->>OC: POST /webhook/telegram/{hash}
    Note over OC: WebhookController (Octane ingress)

    OC->>TS: runForTenant(tenant_id, callable)
    activate TS
    Note over TS: Оборачивает в try/finally

    TS->>TC: set(tenant)
    activate TC
    Note over TC: scoped binding —<br/>новый экземпляр на каждый запрос

    TC->>DB: SET search_path = tenant_{slug}
    Note over DB: PostgreSQL schema switch

    TS->>OC: callable() → обработка запроса
    OC->>Q: dispatch IncomingMessageJob(tenant_id, payload)
    OC-->>TG: 200 OK

    TS-->>TC: finally: restore(previous_tenant)
    deactivate TC
    deactivate TS
    Note over TC: Изоляция между запросами<br/>(Octane безопасен)

    Note over Q,DB: Путь 2 — Queue Job (Horizon Worker)

    Q->>JW: process IncomingMessageJob
    Note over JW: Отдельный process,<br/>свой DI-контейнер

    JW->>TC2: set(tenant_id)
    activate TC2
    Note over TC2: scoped binding —<br/>новый экземпляр per-job

    TC2->>DB: SET search_path = tenant_{slug}
    Note over DB: PostgreSQL schema switch

    JW->>JW: обработка сообщения<br/>FlowOrchestrator, Engine...

    JW-->>TC2: job завершён (implicit cleanup)
    deactivate TC2
    Note over TC2: Process recycled by Horizon,<br/>контекст не вытекает
```

## Ключевые классы

| Класс | Путь | Роль |
|-------|------|------|
| `TenantContextInterface` | `Domains/Tenancy/Contracts/` | Scoped binding — хранит текущий tenant per-request/job |
| `TenantSwitcher` | `Domains/Tenancy/Services/` | `runForTenant(callable)` с `finally restore()` — Octane-safe |
| `TenantDatabaseManager` | `Domains/Tenancy/Services/` | Переключение PostgreSQL search_path на schema tenant |
| `IncomingMessageJob` | `Jobs/` | `TenantContext::set()` в начале handle(), перед любой бизнес-логикой |

## Важные инварианты

- **Scoped, не Singleton:** `TenantContext` зарегистрирован как `scoped` — новый экземпляр на каждый HTTP-запрос и Job, нет state leakage
- **Octane только для webhook** (ADR-01): `/webhook/*` → Octane + `TenantSwitcher::runForTenant()`, всё остальное → PHP-FPM
- **Hard fail без контекста:** `TenantContext::get()` бросает исключение если tenant не установлен — никаких fallback к default tenant
- **Landlord DB не в hot path:** tenant резолвится из Redis по `public_hash`, `landlord` БД не участвует при обработке webhook

## Связано с
- [[01-octane-ingress-only|ADR-01 Octane]] — TenantSwitcher в Octane
- [[architecture/platform/01-overview-layers|Platform: Overview]]
- [[01-webhook-pipeline]]
- [[01-overview-layers]] — архитектура платформы
- [[08-concurrency-idempotency]] — concurrency
- [[diagrams/01-webhook-pipeline]] — диаграмма webhook pipeline
