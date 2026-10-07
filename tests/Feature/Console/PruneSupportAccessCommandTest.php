<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Tenancy\Models\SupportAccessToken;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Feature\FeatureTestCase;

final class PruneSupportAccessCommandTest extends FeatureTestCase
{
    public function test_it_removes_only_tokens_that_expired_long_ago(): void
    {
        $this->token('old', now()->subDays(3));
        $this->token('fresh', now()->addMinute());

        $this->artisan('support-access:prune')->assertSuccessful();

        $this->assertSame(['fresh'], SupportAccessToken::query()->pluck('operator_name')->all());
    }

    public function test_it_is_scheduled(): void
    {
        $commands = collect($this->app->make(Schedule::class)->events())->map(fn ($event): string => (string) $event->command);

        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'support-access:prune')));
    }

    private function token(string $name, DateTimeInterface $expiresAt): void
    {
        SupportAccessToken::query()->create([
            'tenant_id'      => '00000000-0000-0000-0000-000000000001',
            'token_hash'     => hash('sha256', $name),
            'operator_ref'   => 'operator:1',
            'operator_name'  => $name,
            'operator_email' => 'o@example.com',
            'expires_at'     => $expiresAt,
            'created_at'     => now(),
        ]);
    }
}
