<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Solution\Console\ConsoleExtensionRegistry;
use App\Domains\Tenancy\Support\TenantHost;
use Closure;
use Fapost\Foundation\Console\ConsoleNames;
use Illuminate\Routing\Router;

/**
 * Registers the routes Solutions declared through `routes()` and `assistantRoutes()` (ADR solution-console-ui), with
 * everything a Solution may not choose itself: the panel domain, the prefix `apps/<id>/` or
 * `assistant/{tenant}/apps/<id>/`, the names `solutions.<id>.`, the middleware group `solution` or `solution.assistant`
 * and, when the Solution's application has one, its ability.
 *
 * Called from routes/inertia.php, so only with the console switched on and, under `route:cache`, only when the cache is
 * built: a Solution's closures register controllers, never closures of their own.
 */
final readonly class SolutionRoutes
{
    public function __construct(
        private ConsoleExtensionRegistry $registry,
        private Router $router,
    ) {
    }

    public function register(): void
    {
        foreach ($this->registry->tenantRoutes() as $id => $closures) {
            $this->group($id, 'solution', 'apps/' . $id, $closures);
        }

        foreach ($this->registry->assistantRouteClosures() as $id => $closures) {
            $this->group($id, 'solution.assistant', 'assistant/{tenant}/apps/' . $id, $closures);
        }
    }

    /**
     * @param  list<Closure>  $closures
     */
    private function group(string $id, string $stack, string $prefix, array $closures): void
    {
        $ability    = $this->registry->applicationOf($id)?->ability;
        $middleware = null === $ability ? [$stack] : [$stack, 'can:' . $ability];

        $domain = TenantHost::panelDomain();

        $this->router->group(
            [
                ...(null === $domain ? [] : ['domain' => $domain]),
                'middleware' => $middleware,
                'prefix'     => $prefix,
                'as'         => ConsoleNames::ROUTE_PREFIX . $id . '.',
            ],
            static function (Router $router) use ($closures): void {
                foreach ($closures as $closure) {
                    $closure($router);
                }
            },
        );
    }
}
