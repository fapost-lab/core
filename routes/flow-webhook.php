<?php

declare(strict_types=1);

use App\Http\Controllers\Flow\FlowWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook triggers of flows
|--------------------------------------------------------------------------
| Registered WITHOUT the web group on purpose: an outside system's POST must start no session, set no cookie and carry
| no CSRF. The tenant comes from the host (`tenant`); the token in the path names the trigger, and its secret or
| signature authenticates the request (FlowWebhookReceiver, which also holds the rate limits). POST only: any other
| method is answered 405 by the router.
*/

Route::middleware(['tenant'])
    ->post('hooks/flow/{token}', FlowWebhookController::class)
    // 32 random bytes in base64url (FlowWebhookEndpointService::newToken()): anything else cannot be a token.
    ->where('token', '[A-Za-z0-9_-]{43}')
    ->name('flow.webhook');
