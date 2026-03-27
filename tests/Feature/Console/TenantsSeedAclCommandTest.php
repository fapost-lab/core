<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use Tests\TestCase;

final class TenantsSeedAclCommandTest extends TestCase
{
    public function test_completes_when_no_active_tenants(): void
    {
        $this->mock(TenantRepositoryInterface::class, function ($mock): void {
            $mock->shouldReceive('findAllActive')->once()->andReturn([]);
        });

        $this->artisan('tenants:seed-acl', ['--no-interaction' => true])->assertSuccessful();
    }
}
