<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Broadcasting
|--------------------------------------------------------------------------
|
| Laravel's own defaults stand: the default connection is `BROADCAST_CONNECTION` (Core ships `null`, so nothing is
| broadcast and the console polls), and the `reverb`, `pusher`, `ably`, `redis`, `log` and `null` connections are
| read from the framework's configuration. This file adds only where the browser reaches the websocket server.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Browser endpoint
    |--------------------------------------------------------------------------
    |
    | Handed to the console at runtime (the shared `broadcaster` prop) rather than compiled into the front end, so the
    | prebuilt images work on any domain. Empty means the page's own origin: the bundled nginx forwards `/app/` to the
    | `reverb` service. Set them when the websocket server answers somewhere else, e.g. a hosted Pusher-protocol
    | server or a Reverb published on its own host.
    |
    */

    'client' => [
        'host'   => env('BROADCAST_CLIENT_HOST'),
        'port'   => env('BROADCAST_CLIENT_PORT'),
        'scheme' => env('BROADCAST_CLIENT_SCHEME'),
    ],

];
