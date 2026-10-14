<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Shell\SolutionUi;
use Closure;
use Fapost\Foundation\Console\ConsoleNames;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 on every route of a Solution the tenant is not served: not active for it, or its console UI not
 * published (ADR solution-console-ui). The Solution is the one Core named the route after, `solutions.<id>.*`; a route
 * in the group without such a name is refused the same way.
 */
final readonly class EnsureSolutionActive
{
    public function __construct(private SolutionUi $ui)
    {
    }

    /**
     * The Solution a route belongs to by its name, `solutions.<id>.*`.
     */
    public static function solutionOf(Request $request): ?string
    {
        $route = $request->route();
        $name  = $route instanceof Route ? $route->getName() : null;

        if (null === $name || ! str_starts_with($name, ConsoleNames::ROUTE_PREFIX)) {
            return null;
        }

        $id = explode('.', $name)[1] ?? '';

        return '' === $id ? null : $id;
    }

    public function handle(Request $request, Closure $next): Response
    {
        $solution = self::solutionOf($request);

        abort_if(null === $solution || ! $this->ui->isServed($solution), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
