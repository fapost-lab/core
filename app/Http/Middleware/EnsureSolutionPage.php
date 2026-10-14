<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Fapost\Foundation\Console\ConsoleNames;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Solution route renders only its own pages, `<id>::<Path>` (ADR solution-console-ui): an Inertia response with a
 * page of Core or of another Solution is the author's mistake, raised here so tests see it.
 */
final class EnsureSolutionPage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $solution = EnsureSolutionActive::solutionOf($request);
        $page     = $this->page($response);

        if (null !== $solution && null !== $page && ConsoleNames::solutionOf($page) !== $solution) {
            throw new LogicException(sprintf('A route of Solution "%s" rendered the page "%s"; it may render only its own pages, "%s::<Path>".', $solution, $page, $solution));
        }

        return $response;
    }

    /**
     * The Inertia page component of the response: the JSON of an Inertia visit, or the page data of the first load.
     */
    private function page(Response $response): ?string
    {
        $page = match (true) {
            $response instanceof JsonResponse && $response->headers->has('X-Inertia')                  => $response->getData(true),
            $response instanceof IlluminateResponse && $response->getOriginalContent() instanceof View => $response->getOriginalContent()->getData()['page'] ?? null,
            default                                                                                    => null,
        };

        return is_array($page) && is_string($page['component'] ?? null) ? $page['component'] : null;
    }
}
