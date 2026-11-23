<?php

declare(strict_types=1);

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
