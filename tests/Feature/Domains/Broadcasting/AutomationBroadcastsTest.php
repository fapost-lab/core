<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Broadcasting;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\DTOs\AutomationBroadcastRequest;
use App\Domains\Broadcasting\Enums\AutomationBroadcastOutcome;
use App\Domains\Broadcasting\Enums\BroadcastOrigin;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Enums\BroadcastTarget;
use App\Domains\Broadcasting\Jobs\RunBroadcastJob;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Services\AutomationBroadcasts;
use App\Domains\Contact\Contracts\ContactTagRepositoryInterface;
use App\Domains\Tenancy\Settings\TenantSettings;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

/**
 * The automation's entry into Broadcasting: no audit, no current assistant, one broadcast per node execution and at
 * most one draft or running broadcast per flow node — held by unique indexes, not only by the checks before the insert.
 */
final class AutomationBroadcastsTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private Assistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();

        TenantSettings::fake(['content_base_language' => 'ru']);
        Queue::fake([RunBroadcastJob::class]);

        $this->assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);
    }

    public function test_a_plain_text_is_the_base_language_and_the_broadcast_is_running_with_its_origin(): void
    {
        $result = $this->service()->start($this->request('key-1', message: 'Привет'));

        $this->assertSame(AutomationBroadcastOutcome::Started, $result->outcome);

        $broadcast = Broadcast::query()->findOrFail($result->broadcastId);
        $this->assertSame(['ru' => 'Привет'], $broadcast->message);
        $this->assertSame(BroadcastStatus::Running, $broadcast->status);
        $this->assertSame(BroadcastOrigin::Automation, $broadcast->origin);
        $this->assertSame(['vip'], $broadcast->target_tags);
        $this->assertSame(3, $broadcast->chain_depth);
        Queue::assertPushed(RunBroadcastJob::class, 1);
    }

    public function test_a_finished_broadcast_is_still_the_answer_to_its_own_key(): void
    {
        $first = $this->service()->start($this->request('key-1'));
        Broadcast::query()->update(['status' => BroadcastStatus::Completed->value]);

        $again = $this->service()->start($this->request('key-1'));

        $this->assertSame(AutomationBroadcastOutcome::Replayed, $again->outcome);
        $this->assertSame($first->broadcastId, $again->broadcastId);
        $this->assertSame(1, Broadcast::query()->count());
    }

    public function test_the_database_refuses_a_second_live_broadcast_of_a_node_and_a_second_row_for_a_key(): void
    {
        $this->service()->start($this->request('key-1'));
        $row = Broadcast::query()->sole()->only(['tenant_id', 'assistant_id', 'name', 'target_type', 'flow_id', 'flow_node_id']);

        foreach ([['status' => 'running', 'origin_key' => 'key-2'], ['status' => 'completed', 'origin_key' => 'key-1']] as $attributes) {
            try {
                // A savepoint: on Postgres a refused statement aborts the surrounding transaction.
                Broadcast::query()->getConnection()->transaction(static fn () => Broadcast::query()->create([...$row, ...$attributes, 'origin' => 'automation']));
                $this->fail('the insert should have been refused: ' . json_encode($attributes));
            } catch (UniqueConstraintViolationException) {
                $this->addToAssertionCount(1);
            }
        }

        // A finished one of the same node and console broadcasts (no node, no key) are not held back.
        Broadcast::query()->create([...$row, 'status' => 'completed', 'origin_key' => 'key-3', 'origin' => 'automation']);
        Broadcast::query()->create([...$row, 'status' => 'running', 'flow_id' => null, 'flow_node_id' => null]);
        Broadcast::query()->create([...$row, 'status' => 'running', 'flow_id' => null, 'flow_node_id' => null]);

        $this->assertSame(4, Broadcast::query()->count());
    }

    public function test_tags_with_no_text_are_an_audience_that_is_missing(): void
    {
        $result = $this->service()->start($this->request('key-1', tags: [' ', '']));

        $this->assertSame(AutomationBroadcastOutcome::AudienceMissing, $result->outcome);
        $this->assertSame(0, Broadcast::query()->count());
    }

    public function test_a_concurrent_request_of_the_same_node_execution_gets_the_winners_broadcast_back(): void
    {
        $competitor = null;

        $this->competeOnTheLiveCheck(function () use (&$competitor): void {
            $competitor = $this->competingBroadcast('key-1', BroadcastStatus::Running);
        });

        $result = $this->service()->start($this->request('key-1'));

        $this->assertNotNull($competitor);
        $this->assertSame(AutomationBroadcastOutcome::Replayed, $result->outcome);
        $this->assertSame((string) $competitor->getKey(), $result->broadcastId);
        $this->assertSame(1, Broadcast::query()->count());
    }

    public function test_a_concurrent_request_of_another_run_of_the_node_is_busy(): void
    {
        $this->competeOnTheLiveCheck(function (): void {
            $this->competingBroadcast('key-other', BroadcastStatus::Running);
        });

        $result = $this->service()->start($this->request('key-1'));

        $this->assertSame(AutomationBroadcastOutcome::Busy, $result->outcome);
        $this->assertNull($result->broadcastId);
        $this->assertSame(['key-other'], Broadcast::query()->pluck('origin_key')->all());
    }

    public function test_a_reach_that_cannot_be_counted_does_not_fail_a_started_broadcast(): void
    {
        $tags = Mockery::mock(ContactTagRepositoryInterface::class);
        $tags->shouldReceive('contactIdsWithTag')->andThrow(new RuntimeException('count failed'));
        $this->app->instance(ContactTagRepositoryInterface::class, $tags);

        $result = $this->service()->start($this->request('key-1'));

        $this->assertSame(AutomationBroadcastOutcome::Started, $result->outcome);
        $this->assertSame(0, $result->recipientCount);
        $this->assertSame(1, Broadcast::query()->count());
    }

    public function test_a_replay_reports_the_audience_the_run_fixed_instead_of_recounting(): void
    {
        $first = $this->service()->start($this->request('key-1'));
        Broadcast::query()->update(['total_recipients' => 7, 'status' => BroadcastStatus::Completed->value]);

        $again = $this->service()->start($this->request('key-1'));

        $this->assertSame(AutomationBroadcastOutcome::Replayed, $again->outcome);
        $this->assertSame($first->broadcastId, $again->broadcastId);
        $this->assertSame(7, $again->recipientCount);
    }

    /**
     * Runs the callback once, when the service first asks whether the node has a live broadcast: the moment between its
     * checks and its insert, in which a concurrent request can win. Not a model event: that would sit in the
     * insert's own transaction and be rolled back with it.
     */
    private function competeOnTheLiveCheck(Closure $competitor): void
    {
        $fired = false;

        DB::listen(static function (QueryExecuted $query) use (&$fired, $competitor): void {
            if (! $fired && str_contains($query->sql, '"broadcasts"') && str_contains($query->sql, 'flow_node_id') && str_starts_with($query->sql, 'select exists')) {
                $fired = true;
                $competitor();
            }
        });
    }

    private function competingBroadcast(string $originKey, BroadcastStatus $status): Broadcast
    {
        return Broadcast::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => (string) $this->assistant->getKey(),
            'name'         => 'Competitor',
            'message'      => ['ru' => 'Привет'],
            'target_type'  => BroadcastTarget::Tags->value,
            'target_tags'  => ['vip'],
            'status'       => $status->value,
            'origin'       => BroadcastOrigin::Automation->value,
            'flow_id'      => '01938f3e-7c1a-7d2b-8e4f-123456789abc',
            'flow_node_id' => 'tell',
            'origin_key'   => $originKey,
        ]);
    }

    private function service(): AutomationBroadcasts
    {
        return $this->app->make(AutomationBroadcasts::class);
    }

    /**
     * @param  list<string>                  $tags
     * @param  array<string, string>|string  $message
     */
    private function request(string $key, array $tags = ['vip', 'vip'], array|string $message = ['ru' => 'Привет']): AutomationBroadcastRequest
    {
        return new AutomationBroadcastRequest(
            tenantId: self::TENANT_ID,
            assistantId: (string) $this->assistant->getKey(),
            name: 'Automation',
            target: BroadcastTarget::Tags,
            tags: $tags,
            segmentId: null,
            message: $message,
            flowId: '01938f3e-7c1a-7d2b-8e4f-123456789abc',
            flowSessionId: (string) Str::uuid(),
            flowNodeId: 'tell',
            originKey: $key,
            chainDepth: 3,
        );
    }
}
