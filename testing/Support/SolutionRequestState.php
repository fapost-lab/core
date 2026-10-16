<?php

declare(strict_types=1);

namespace Fapost\Testing\Support;

use App\Domains\Solution\Services\ActiveSolutions;
use App\Domains\Solution\Services\PublishedSolutionUi;
use App\Domains\Solution\Services\SolutionEntitlements;
use App\Http\Shell\SolutionExtensions;
use App\Http\Shell\SolutionUi;
use App\Http\Shell\SolutionUiMapInterface;
use Illuminate\Contracts\Foundation\Application;

/**
 * What a request or the process remembers about Solutions, in one list.
 *
 * A test changes Solution state (activates one, publishes a bundle) inside a single process, where a real
 * deployment would see a new request start without those singletons. Forgetting them here is how a test simulates
 * that new request; the list lives in one place so Core's own fixtures and the kit cannot drift apart.
 *
 * @internal not part of the testing kit's API: which singletons Core keeps is Core's business
 */
final class SolutionRequestState
{
    /**
     * @var list<class-string>
     */
    public const array SINGLETONS = [
        ActiveSolutions::class,
        SolutionEntitlements::class,
        PublishedSolutionUi::class,
        SolutionUi::class,
        SolutionUiMapInterface::class,
        SolutionExtensions::class,
    ];

    public static function forget(Application $app): void
    {
        foreach (self::SINGLETONS as $abstract) {
            $app->forgetInstance($abstract);
        }
    }
}
