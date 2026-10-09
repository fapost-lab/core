<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Contact\Models\LimitRefusal;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Feature\FeatureTestCase;

final class PruneLimitRefusalsCommandTest extends FeatureTestCase
{
    public function test_it_removes_only_refusals_older_than_the_retention(): void
    {
        config(['quota.refusals_retention_days' => 90]);
        $this->refusal('old', now()->subDays(91));
        $this->refusal('edge', now()->subDays(89));
        $this->refusal('fresh', now());

        $this->artisan('limit-refusals:prune')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['edge', 'fresh'], LimitRefusal::query()->pluck('subject_hash')->all());
    }

    public function test_it_is_scheduled_daily(): void
    {
        $commands = collect($this->app->make(Schedule::class)->events())->map(fn ($event): string => (string) $event->command);

        $this->assertTrue($commands->contains(fn (string $command): bool => str_contains($command, 'limit-refusals:prune')));
    }

    private function refusal(string $hash, DateTimeInterface $day): void
    {
        LimitRefusal::query()->create([
            'limit_key'       => 'monthly_active_contacts',
            'subject_hash'    => $hash,
            'channel_id'      => '00000000-0000-0000-0000-0000000000c1',
            'refused_on'      => $day->format('Y-m-d'),
            'attempts'        => 1,
            'last_refused_at' => $day,
        ]);
    }
}
