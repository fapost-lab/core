<?php

declare(strict_types=1);

namespace Tests\Fixtures\Solution;

/**
 * Records what reached the fixture handlers. Tests bind it as a singleton for one test.
 */
final class FeedbackCalls
{
    /** @var list<array{action: string, tenant_id: string, parameters: array<string, mixed>}> */
    public array $calls = [];

    public int $constructions = 0;
}
