<?php

declare(strict_types=1);

use App\Http\Controllers\Media\PublicMediaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public media links
|--------------------------------------------------------------------------
| Registered WITHOUT the web group on purpose: an anonymous fetch by a messenger's servers must start no session,
| set no cookie and carry no CSRF. The tenant comes from the host (`tenant`); the signature in the path is the
| authorization, see PublicMediaLinkResolver. The rate limit is per link (MediaServiceProvider).
*/

Route::middleware(['tenant', 'throttle:media-public-link'])
    ->get('media/public/{file}/{expires}/{signature}/{segment}', PublicMediaController::class)
    ->where([
        // A lowercase RFC 4122 id (HasUlidPrimaryKey): anything else would reach PostgreSQL's uuid column as an error, not a 404.
        'file' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}',
        // No leading zero: one moment has one spelling, so one link has one rate-limit key.
        'expires'   => '[1-9][0-9]{0,11}',
        'signature' => '[A-Za-z0-9_-]{43}',
        'segment'   => '[A-Za-z0-9._-]{1,80}',
    ])
    ->name('media.public');
