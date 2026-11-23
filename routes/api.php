<?php

declare(strict_types=1);

use App\Http\Controllers\Flow\FlowApiTriggerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The tenant API
|--------------------------------------------------------------------------
| Registered under `api/v1` on the `api-token` stack (bootstrap/app.php), WITHOUT the web group: outside systems call it
| with a tenant API token (`Authorization: Bearer fpt_…`), with no session, CSRF or cookie. The tenant comes from the
| host, then the token is looked up in that tenant (adr-20261119-tenant-api-tokens §5).
|
| A route here authorizes by `Permission` value only — `Gate::allows(Permission::X->value)` — never by a policy ability,
| which the token's fail-closed Gate branch refuses (§7).
*/

// Start a run of a published flow through its API trigger (FlowApiTriggerController). A trigger id is a ULID written
// as a uuid (the builder mints it, adr-20261119-flow-triggers-in-definition-live-on-publish); anything else cannot be
// one, and never reaches the `uuid` column.
Route::post('flow-triggers/{trigger}/runs', FlowApiTriggerController::class)
    ->whereUuid('trigger')
    ->name('api.flow-triggers.runs.store');
