<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Audit\Models\ActivityEntry;
use App\Domains\Audit\Services\AuditRetention;
use App\Domains\Tenancy\Services\PlatformSettings;
use DateTimeInterface;
use Tests\Feature\FeatureTestCase;

/**
 * `audit:prune` deletes every active tenant's audit entries older than the platform setting `audit.retention_days`;
 * 0 keeps them forever, a dry run only counts.
 */
final class PruneActivityLogCommandTest extends FeatureTestCase
{
    public function test_the_default_retention_is_a_year_and_unset_is_not_forever(): void
    {
        $this->assertSame(365, AuditRetention::parseDays(null));
        $this->assertSame(0, AuditRetention::parseDays('0'));
        $this->assertSame(90, AuditRetention::parseDays(' 90 '));
        $this->assertSame(365, AuditRetention::parseDays('12m'));
        $this->assertSame(365, AuditRetention::parseDays('99999'));
    }

    public function test_entries_older_than_the_retention_are_deleted_in_the_tenant(): void
    {
        $this->store(30);
        $old    = $this->entry(now()->subDays(31));
        $recent = $this->entry(now()->subDays(29));

        $this->artisan('audit:prune')->expectsOutput('Deleted entries: 1')->assertSuccessful();

        $this->assertFalse(ActivityEntry::query()->whereKey($old->getKey())->exists());
        $this->assertTrue(ActivityEntry::query()->whereKey($recent->getKey())->exists());
    }

    public function test_a_dry_run_counts_and_deletes_nothing(): void
    {
        $this->store(30);
        $this->entry(now()->subDays(40));

        $this->artisan('audit:prune', ['--dry-run' => true])->expectsOutput('Entries to delete: 1')->assertSuccessful();

        $this->assertSame(1, ActivityEntry::query()->count());
    }

    public function test_zero_keeps_the_log_forever(): void
    {
        $this->store(0);
        $this->entry(now()->subYears(5));

        $this->artisan('audit:prune')->expectsOutputToContain('retention is disabled')->assertSuccessful();

        $this->assertSame(1, ActivityEntry::query()->count());
    }

    private function store(int $days): void
    {
        $this->app->make(PlatformSettings::class)->update([AuditRetention::SETTING => $days], 'cli:test');
        $this->app->forgetScopedInstances();
    }

    private function entry(DateTimeInterface $at): ActivityEntry
    {
        return ActivityEntry::query()->create([
            'log_name'    => 'settings',
            'description' => 'settings.updated',
            'event'       => 'updated',
            'actor_kind'  => 'system',
            'created_at'  => $at,
        ]);
    }
}
