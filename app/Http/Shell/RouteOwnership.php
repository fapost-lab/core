<?php

declare(strict_types=1);

namespace App\Http\Shell;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Tells which stack answers a named route: the Inertia console, or still Filament.
 *
 * A screen has moved when its route is answered by a controller of the console (see routes/inertia.php). The shell
 * links to a moved screen with an Inertia visit and to every other one with a plain link, a full page load.
 */
final class RouteOwnership
{
    private const array CONSOLE_CONTROLLERS = [
        'App\\Http\\Controllers\\Console\\',
        'App\\Http\\Controllers\\Admin\\',
    ];

    public function exists(string $routeName): bool
    {
        return Route::has($routeName);
    }

    public function isMigrated(string $routeName): bool
    {
        $route = Route::getRoutes()->getByName($routeName);

        if (null === $route) {
            return false;
        }

        $class = Str::before($route->getActionName(), '@');

        foreach (self::CONSOLE_CONTROLLERS as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        return false;
    }
}
