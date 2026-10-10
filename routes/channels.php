<?php

declare(strict_types=1);

use App\Domains\Flow\Live\FlowActivityChannel;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Private broadcast channels
|--------------------------------------------------------------------------
|
| Authorized at `/broadcasting/auth` behind the `broadcasting` middleware group (bootstrap/app.php): the tenant of the
| host first, then the session and the same account checks the console runs. With the `null` broadcaster, Core's
| default, nothing is ever broadcast and the screens poll instead.
*/

Broadcast::channel(FlowActivityChannel::PATTERN, FlowActivityChannel::class);
