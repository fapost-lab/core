# Tenant Context: setting the context and switching the schema

Tenant context is established in the **worker**, not at the ingress. The webhook ingress resolves the
channel from Redis and dispatches a job; the queue job switches the tenant.

```mermaid
sequenceDiagram
    participant TG as Telegram API
    participant OC as Ingress<br/>(Go gateway or WebhookController)
    participant RD as Redis
    participant Q as Queue<br/>flow.execution
    participant JW as Horizon worker<br/>IncomingMessageJob
    participant TS as TenantSwitcher<br/>(scoped)
    participant TC as TenantContext<br/>(scoped)
    participant DM as TenantDatabaseManager<br/>(scoped)
    participant DB as PostgreSQL<br/>tenant schema

    Note over TG,RD: Path 1: HTTP webhook (no tenant context)

    TG->>OC: POST /webhook/{channel}/{hash}
    OC->>RD: GET webhook:{hash}
    RD-->>OC: {tenant_id, schema, assistant_id, ...}
    Note over OC: No Eloquent, no TenantContext,<br/>no TenantSwitcher: the entry is a plain value object
    OC->>Q: dispatch IncomingMessageJob(tenant_id, schema, rawPayload)
    OC-->>TG: 200 OK

    Note over Q,DB: Path 2: queue job (Horizon worker)

    Q->>JW: handle(InboundWebhookPayload)
    JW->>TS: runForTenant(RuntimeTenant(id, schema), callback)
    activate TS
    TS->>TC: set(tenant)
    TS->>DM: switchTo(tenant)
    DM->>DB: set search_path = tenant schema<br/>default connection = tenant connection
    TS->>JW: callback(): normalise, resolve contact,<br/>MessageRouter, FlowOrchestrator, FlowEngine
    JW-->>TS: returns or throws
    TS->>DM: finally: restore() (pop the connection stack)
    TS->>TC: finally: restore previous tenant, or reset()
    deactivate TS
```

## Key classes

| Class | Path | Role |
|-------|------|------|
| `TenantContextInterface` / `TenantContext` | `app/Domains/Tenancy/Contracts/`, `app/Domains/Tenancy/Services/TenantContext.php` | Holds the current tenant; `get()` throws `TenantNotResolvedException` when unset |
| `TenantSwitcher` | `app/Domains/Tenancy/Services/TenantSwitcher.php` | `runForTenant(tenant, callback)` with a `finally` restore; nested calls restore the outer tenant; also resets cached permissions and runs restore hooks (for example `CurrentAssistant`) |
| `TenantDatabaseManager` | `app/Domains/Tenancy/Database/TenantDatabaseManager.php` | `switchTo()` / `restore()`: pushes the previous connection on a stack, sets the tenant connection's PostgreSQL `search_path` to the tenant schema |
| `TenantPostgresConnection` | `app/Domains/Tenancy/Database/TenantPostgresConnection.php` | pgsql connection class that lets `search_path` move while the connection stays open (no dropped transaction) |
| `IncomingMessageJob` | `app/Domains/Webhook/Jobs/IncomingMessageJob.php` | Wraps all domain work in `TenantSwitcher::runForTenant()` |

## Important invariants

- **Scoped, not singleton:** `TenantContextInterface`, `TenantDatabaseManagerInterface` and
  `TenantSwitcher` are registered with `scoped()` in `DomainServiceProvider`, so a Horizon job gets
  a fresh instance and the scope is flushed between jobs. Nothing tenant-specific may live in a
  singleton or in static state (see the worker-safety convention).
- **Restore is mandatory:** one Horizon worker runs many jobs in a row; a tenant left set would leak
  into the next job. `runForTenant()` always restores in `finally`, even when the callback throws or
  the restore itself fails.
- **Hard fail without context:** `TenantContext::get()` throws when no tenant is set. There is no
  fallback to a default tenant on the worker path.
- **The landlord database is not on the webhook hot path:** the channel resolves from Redis by
  `hash`; only a Redis miss falls back to the landlord `webhook_registry` table (through the Tenancy
  domain's reader).
- The webhook controller never switches the tenant. Schema switching happens only inside jobs,
  console commands and HTTP requests that go through the tenancy middleware.

## Related

- [01-webhook-pipeline.md](01-webhook-pipeline.md)
- `.ai/knowledge/domains/tenancy/OVERVIEW.md`, `.ai/knowledge/domains/tenancy/RULES.md`
- `.ai/knowledge/conventions/worker-safety.md`
