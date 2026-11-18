<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Flow\Services\ValidateFlowService;
use App\Domains\Flow\Validation\ListSizeChecker;
use ReflectionProperty;
use Tests\TestCase;

final class ListSizeCheckerWiringTest extends TestCase
{
    public function test_the_container_builds_the_validator_with_the_list_size_checker(): void
    {
        $service = $this->app->make(ValidateFlowService::class);

        $this->assertInstanceOf(ListSizeChecker::class, (new ReflectionProperty($service, 'listSize'))->getValue($service));
    }
}
