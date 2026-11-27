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
reviewed_at: 2026-11-26
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

The database is such a collaborator too: a domain service that opens a transaction
takes `Illuminate\Database\ConnectionResolverInterface` and asks it for
`connection()` at the moment it writes, never `ConnectionInterface` in the
constructor. A tenant switch moves the *default* connection the Eloquent models write
through (`TenantDatabaseManager::switchTo()`), and a service — or the controller that
receives it — may be built before that switch; a connection captured at construction
would then open its transaction on another connection than the models' writes, and
the write and its audit entry would silently stop being atomic.
`CredentialWriter` and `OAuth2Consent` (`app/Domains/Credentials/`) follow this;
`CredentialWriterTest` pins it with `tests/Support/RecordingConnectionResolver.php`.
The same holds for a service that writes with the query builder (`->table()->insert()`) or
runs DDL: a captured connection writes into the schema it pointed at when it was built.

Where it bites: a queue job resolves its `handle()` dependencies before the body enters
`TenantSwitcher::runForTenant()` (`StartAutomationRunJob` takes `AutomationRunner`, and with it
the scoped `FlowEngine` graph), so anything captured there is the worker's default connection.
While `TENANT_DB_CONNECTION` names the same connection as `DB_CONNECTION` (the default) the
switch only moves its `search_path` in place and a captured connection happens to be the right
object; it goes wrong as soon as the tenant connection is another one. Controllers are built
after route middleware here, but the rule does not rely on that. Every Flow service follows it,
and `tests/Unit/Domains/Flow/FlowConnectionInjectionTest.php` fails on a `ConnectionInterface`,
`Connection` or `DatabaseManager` constructor parameter in `App\Domains\Flow` (and in
`ConnectionAfterCommitDispatcher`, which must defer callbacks on the connection the transaction
is on). The one exception is `ContactWriter`: the engine builds it for a single node step and
hands it the connection it resolved for that step. A per-class test with
`RecordingConnectionResolver` cannot see a capture on its own: an autowired `ConnectionInterface`
comes from `db.connection`, not from the resolver bound in the test.

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
