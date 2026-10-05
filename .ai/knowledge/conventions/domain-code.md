---
id: convention-domain-code
type: convention
status: active
domains: []
paths:
  - "app/Domains/**"
  - "app/Http/Controllers/**"
  - "app/Jobs/**"
summary: Controllers/jobs orchestrate; domain services take collaborators via constructor, not app()/resolve()
reviewed_at: 2026-10-05
---
# Domain code boundaries

## Practice

Controllers and jobs orchestrate; business logic lives in services and domain
classes, not inline in the controller/job method. A domain service declares its
collaborators as constructor parameters — never as a hidden `app()`, `resolve()` or
other global Laravel helper call — so it can be built and tested without booting
the framework. Where a domain crosses a persistence boundary or another bounded
context, it depends on a repository or port (a contract), the same pattern Tenancy
requires for landlord access. Facades stay acceptable in the infrastructure layer —
providers, jobs, controllers, migrations, framework adapters — just not inside the
domain/service layer itself. Eloquent models live in `Domains/{Domain}/Models`, and
relations stay defined on the model when Eloquent's query capabilities (eager
loading, `whereHas`, ...) are what's needed, rather than being reimplemented in a
service.

## Example

`AssistantSwitchListService::forUser()`
(`app/Domains/Assistant/Services/AssistantSwitchListService.php`) takes
`TenantContextInterface` through its constructor instead of resolving it, and
composes the query against `Assistant::query()`, driving the model's own `users()`
relation through `whereHas()` rather than reimplementing the join. Contrast
`PreSaleController::store()` (`app/Http/Controllers/PreSaleController.php`): it is
thin orchestration that calls `Mail::to(...)->queue(...)` directly — a facade in a
controller is infrastructure, not a domain service hiding a dependency.

## Rationale

Keeping business logic out of controllers/jobs, and collaborators out of hidden
`app()`/`resolve()` lookups, is what makes a domain service constructible and
testable in isolation — and it is what `conventions/worker-safety.md`
depends on: a hidden global lookup is exactly the kind of state a Horizon worker
can leak between tenants across jobs. `tests/Architecture/FlowRuntimeIsolationTest.php`
enforces the no-facades / no-container-as-service-locator half of this for the Flow
runtime specifically (`App\Domains\Flow\Handlers`, `Orchestration`, `Registry`,
`Routing`, `State`, `Subflow`, plus `FlowEngine`). Everywhere else in the domain
layer this is Review only — `.ai/knowledge/RULES.md` records it as such, and nothing
fails automatically when a domain service outside Flow reaches for `app()`.
