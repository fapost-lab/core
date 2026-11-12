<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domains\Solution\Console\ConsoleExtensionRegistry;
use App\Http\Shell\SolutionExtensions;
use App\Http\Shell\SolutionUi;
use Closure;
use Fapost\Foundation\Console\ConsoleNames;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 on every route of a Solution the tenant is not served: not active for it, or its console UI not
 * published (ADR solution-console-ui). The Solution is the one Core named the route after, `solutions.<id>.*`; a route
 * in the group without such a name is refused the same way.
 *
 * An active Solution whose console UI no longer fits the kit, Vue or Inertia this Core ships
 * ({@see SolutionUi::needsUpdate()}) answers 503 instead, to a user its application lets in: a page request gets Core's
 * `Errors/SolutionUnavailable` page inside the shell, saying the Solution needs an update, and a JSON request a bare
 * 503; a user the application does not let in still gets 404.
 */
final readonly class EnsureSolutionActive
{
    public function __construct(private SolutionUi $ui, private ConsoleExtensionRegistry $registry, private SolutionExtensions $extensions)
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

        if (null !== $solution && $this->ui->needsUpdate($solution) && $this->mayOpen($request, $solution)) {
            return $this->updateRequired($request);
        }

        abort_if(null === $solution || ! $this->ui->isServed($solution), Response::HTTP_NOT_FOUND);

        return $next($request);
    }

    /**
     * Whether the user has the ability of the Solution's application, which the route group checks only after this
     * middleware: without it the Solution stays a 404, as it would be once it is updated.
     */
    private function mayOpen(Request $request, string $solutionId): bool
    {
        return $this->extensions->allows($request->user(), $this->registry->applicationOf($solutionId)?->ability);
    }

    private function updateRequired(Request $request): Response
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return Inertia::render('Errors/SolutionUnavailable', ['reason' => 'update_required'])
            ->toResponse($request)
            ->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
