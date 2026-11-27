<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowSessionHistoryEntry;
use App\Domains\Flow\Retention\SessionHistoryRetention;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\PlatformSettings;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * `flow:prune-session-history` reads the retention setting and prunes every active tenant through
 * {@see \App\Domains\Flow\Retention\SessionHistoryPruner}; which histories go is proven in `SessionHistoryPrunerTest`.
 */
final class PruneSessionHistoryCommandTest extends FeatureTestCase
{
    private string $tenantId;

    private FlowDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId   = $this->app->make(TenantRepositoryInterface::class)->findAllActive()[0]->getId();
        $this->definition = FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Retention',
            'nodes'     => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
            'edges'     => [],
            'is_active' => true,
        ]);
    }

    public function test_env_values_outside_the_range_keep_the_history_forever(): void
    {
        $this->assertSame(0, SessionHistoryRetention::parseDays(null));
        $this->assertSame(0, SessionHistoryRetention::parseDays('90d'));
        $this->assertSame(0, SessionHistoryRetention::parseDays('-1'));
        $this->assertSame(0, SessionHistoryRetention::parseDays('99999'));
        $this->assertSame(90, SessionHistoryRetention::parseDays(' 90 '));
    }

    public function test_by_default_nothing_is_deleted(): void
    {
        $session = $this->flowRun(FlowSessionStatus::Completed, 4000);

        $this->artisan('flow:prune-session-history')->expectsOutputToContain('retention is disabled')->assertSuccessful();

        $this->assertTrue($this->hasHistory($session));
    }

    public function test_a_stored_retention_deletes_the_history_of_old_finished_sessions_only(): void
    {
        $this->store(90);
        $old     = $this->flowRun(FlowSessionStatus::Completed, 91);
        $young   = $this->flowRun(FlowSessionStatus::Completed, 89);
        $waiting = $this->flowRun(FlowSessionStatus::WaitingInput, 400);

        $this->artisan('flow:prune-session-history')
            ->expectsOutput('Sessions whose history was deleted: 1')
            ->assertSuccessful();

        $this->assertFalse($this->hasHistory($old));
        $this->assertTrue($this->hasHistory($young));
        $this->assertTrue($this->hasHistory($waiting));
        $this->assertSame(3, FlowSession::query()->count());
    }

    public function test_a_dry_run_counts_and_deletes_nothing(): void
    {
        $this->store(30);
        $session = $this->flowRun(FlowSessionStatus::Expired, 60);

        $this->artisan('flow:prune-session-history', ['--dry-run' => true])
            ->expectsOutput('Sessions whose history would be deleted: 1')
            ->assertSuccessful();

        $this->assertTrue($this->hasHistory($session));
    }

    public function test_a_failing_tenant_is_reported_and_the_others_are_still_pruned(): void
    {
        $this->store(30);
        $tenants = $this->app->make(TenantRepositoryInterface::class);
        $real    = $tenants->findAllActive()[0];
        $broken  = new RuntimeTenant(id: (string) Str::uuid(), schemaName: 'broken', slug: 'broken');
        $old     = $this->flowRun(FlowSessionStatus::Failed, 60);

        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $repository->allows('findAllActive')->andReturn([$broken, $real]);
        $this->app->instance(TenantRepositoryInterface::class, $repository);

        $entries = 0;
        $this->app->make(TenantSwitcher::class)->registerContextHook(function () use (&$entries): Closure {
            if (1 === ++$entries) {
                throw new RuntimeException('schema unavailable');
            }

            return static function (): void {
            };
        });

        $this->artisan('flow:prune-session-history')
            ->expectsOutput('✘ broken: schema unavailable')
            ->expectsOutput('Sessions whose history was deleted: 1')
            ->assertFailed();

        $this->assertFalse($this->hasHistory($old));
    }

    public function test_it_is_scheduled_daily_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'flow:prune-session-history'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('45 4 * * *', $event->expression);
    }

    private function store(int $days): void
    {
        $this->app->make(PlatformSettings::class)->update([SessionHistoryRetention::SETTING => $days], 'cli:test');
        $this->app->forgetScopedInstances();
    }

    private function flowRun(FlowSessionStatus $status, int $daysAgo): FlowSession
    {
        $session = FlowSession::query()->create([
            'tenant_id'          => $this->tenantId,
            'contact_id'         => Contact::factory()->forTenant($this->tenantId)->create()->getKey(),
            'flow_definition_id' => $this->definition->getKey(),
            'flow_version'       => 1,
            'status'             => $status,
            'run_kind'           => FlowRunKind::Conversation,
            'state'              => [],
        ]);

        FlowSession::query()->whereKey($session->getKey())->toBase()->update([
            'updated_at' => CarbonImmutable::now()->subDays($daysAgo),
        ]);

        FlowSessionHistoryEntry::query()->create([
            'tenant_id'  => $this->tenantId,
            'session_id' => $session->getKey(),
            'node_id'    => 'end',
            'event_type' => 'node_entered',
            'created_at' => CarbonImmutable::now()->subDays($daysAgo),
        ]);

        return $session;
    }

    private function hasHistory(FlowSession $session): bool
    {
        return FlowSessionHistoryEntry::query()->where('session_id', $session->getKey())->exists();
    }
}
