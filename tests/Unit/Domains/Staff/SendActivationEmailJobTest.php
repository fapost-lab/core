<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Staff;

use App\Domains\Staff\Jobs\SendActivationEmailJob;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class SendActivationEmailJobTest extends TestCase
{
    public function test_dispatch_carries_tenant_id_user_id_and_plain_token(): void
    {
        Bus::fake();

        SendActivationEmailJob::dispatch('550e8400-e29b-41d4-a716-446655440000', 42, 'plain-secret');

        Bus::assertDispatched(SendActivationEmailJob::class, static fn (SendActivationEmailJob $job): bool => '550e8400-e29b-41d4-a716-446655440000' === $job->tenantId
                && 42 === $job->userId
                && 'plain-secret' === $job->plainToken);
    }
}
