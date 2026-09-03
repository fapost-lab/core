<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pages are rendered without a Vite manifest: tests assert on
        // responses, not on compiled assets, and CI has no public/build.
        $this->withoutVite();
    }
}
