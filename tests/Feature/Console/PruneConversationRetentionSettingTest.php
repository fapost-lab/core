<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Conversation\Retention\PartitionRetention;
use App\Domains\Tenancy\Contracts\PlatformSettingsReaderInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\PlatformSettings;
use Mockery;
use Tests\Feature\FeatureTestCase;

/**
 * `conversations:prune` reads the retention from the platform setting: the stored value replaces the configured
 * default, and 0 (stored or default) keeps the transcript forever.
 */
final class PruneConversationRetentionSettingTest extends FeatureTestCase
{
    public function test_nothing_is_pruned_while_neither_a_value_nor_a_default_is_set(): void
    {
        config(['conversation.retention_days' => null]);
        $this->shouldNotListTenants();

        $this->artisan('conversations:prune')->expectsOutputToContain('retention is disabled')->assertSuccessful();
    }

    public function test_the_configured_default_applies_until_a_value_is_stored(): void
    {
        config(['conversation.retention_days' => 30]);

        $this->assertSame(30, $this->reader()->integer(PartitionRetention::SETTING));
        $this->shouldListNoTenants();

        $this->artisan('conversations:prune')->expectsOutput('Dropped partitions: 0')->assertSuccessful();
    }

    public function test_a_stored_value_replaces_a_missing_default(): void
    {
        config(['conversation.retention_days' => null]);
        $this->store(30);

        $this->assertSame(30, $this->reader()->integer(PartitionRetention::SETTING));
        $this->shouldListNoTenants();

        $this->artisan('conversations:prune')->expectsOutput('Dropped partitions: 0')->assertSuccessful();
    }

    public function test_a_stored_zero_turns_off_a_configured_retention(): void
    {
        config(['conversation.retention_days' => 30]);
        $this->store(0);
        $this->shouldNotListTenants();

        $this->artisan('conversations:prune')->expectsOutputToContain('retention is disabled')->assertSuccessful();
    }

    private function store(int $days): void
    {
        $this->app->make(PlatformSettings::class)->update([PartitionRetention::SETTING => $days], 'cli:test');
        $this->app->forgetScopedInstances();
    }

    private function reader(): PlatformSettingsReaderInterface
    {
        $this->app->forgetScopedInstances();

        return $this->app->make(PlatformSettingsReaderInterface::class);
    }

    private function shouldNotListTenants(): void
    {
        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $repository->shouldNotReceive('findAllActive');
        $this->app->instance(TenantRepositoryInterface::class, $repository);
    }

    private function shouldListNoTenants(): void
    {
        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $repository->shouldReceive('findAllActive')->once()->andReturn([]);
        $this->app->instance(TenantRepositoryInterface::class, $repository);
    }
}
