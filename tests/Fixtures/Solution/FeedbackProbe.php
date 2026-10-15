<?php

declare(strict_types=1);

namespace Tests\Fixtures\Solution;

/**
 * A service the fixture handlers take in their constructor. Tests bind it `scoped`, so each scope
 * (job) gets its own token and a handler built in another scope shows a different one.
 */
final class FeedbackProbe
{
    public readonly string $scopeToken;

    public function __construct()
    {
        $this->scopeToken = bin2hex(random_bytes(4));
    }
}
