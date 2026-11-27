<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Retention\FlowVersionPruner;
use App\Domains\Flow\Services\ValidateFlowService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * The lock a publish takes on the versions it pins, on PostgreSQL, against a second session: it keeps the version
 * retention away from a pinned version and does not stand in the way of another publish.
 *
 * Two sessions must see the same rows, so nothing here runs inside the test transaction; the rows are deleted
 * afterwards. Skipped on SQLite, which has no row locks.
 */
final class FlowVersionPinLockTest extends FeatureTestCase
{
    private const string PROBE = 'pin_lock_probe';

    private string $tenantId;

    /** @var list<string> */
    private array $flowIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if ('pgsql' !== DB::connection()->getDriverName()) {
            $this->markTestSkipped('Row locks are PostgreSQL behaviour.');
        }

        $this->tenantId = (string) Str::uuid();

        $default = (string) config('database.default');
        config(['database.connections.' . self::PROBE => config("database.connections.{$default}")]);
    }

    protected function tearDown(): void
    {
        if ([] !== $this->flowIds) {
            DB::connection(self::PROBE)->rollBack(0);
            DB::connection()->rollBack(0);
            FlowDefinition::query()->whereIn('flow_id', $this->flowIds)->delete();
            DB::purge(self::PROBE);
        }

        parent::tearDown();
    }

    public function test_a_publish_pinning_an_active_version_does_not_block_another_publish_replacing_it(): void
    {
        // Two flows pinning each other's active version, published at once: each validation locks the other's active
        // row, and each publish then sets its own previous active row to inactive. With FOR SHARE the second update
        // waits on the first validation and the two publishes deadlock.
        $child  = $this->publish(2);
        $pinned = $this->definition($child, 2);

        DB::connection()->beginTransaction();
        $this->validatePinning($child, 2);

        $probe = $this->probe();
        $probe->beginTransaction();

        $this->assertSame(1, $probe->update('UPDATE flow_definitions SET is_active = false WHERE id = ?', [$pinned->getKey()]));

        $probe->rollBack();
        DB::connection()->rollBack();
    }

    public function test_the_version_retention_skips_a_version_a_publish_in_progress_has_pinned(): void
    {
        $child = $this->publish(2);

        // The publish is not committed, so its pin is invisible to the prune's scan; only the lock keeps v1.
        $probe = $this->probe();
        $probe->beginTransaction();
        $this->assertSame(
            1,
            count($probe->select('SELECT id FROM flow_definitions WHERE flow_id = ? AND version = 1 FOR KEY SHARE', [$child])),
        );

        $this->assertSame(0, (new FlowVersionPruner())->prune($this->tenantId, 1));
        $this->assertSame(2, FlowDefinition::query()->where('flow_id', $child)->count());

        $probe->rollBack();

        $this->assertSame(1, (new FlowVersionPruner())->prune($this->tenantId, 1));
        $this->assertSame(1, FlowDefinition::query()->where('flow_id', $child)->count());
    }

    public function test_the_pin_check_takes_a_key_share_lock_that_a_delete_waits_for(): void
    {
        $child  = $this->publish(2);
        $pinned = $this->definition($child, 1);

        DB::connection()->beginTransaction();
        $this->validatePinning($child, 1);

        $probe = $this->probe();
        $probe->beginTransaction();

        $this->assertSame([], $probe->select('SELECT id FROM flow_definitions WHERE id = ? FOR UPDATE SKIP LOCKED', [$pinned->getKey()]));

        $probe->rollBack();
        DB::connection()->rollBack();
    }

    /**
     * @return list<string>
     */
    protected function connectionsToTransact(): array
    {
        return [];
    }

    private function validatePinning(string $childFlowId, int $version): void
    {
        $result = $this->app->make(ValidateFlowService::class)->execute(nodes: [
            ['id' => 'sub', 'type' => 'subflow', 'version' => 1, 'config' => ['flow_id' => $childFlowId, 'flow_version' => $version]],
            ['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']],
        ]);

        $this->assertNotContains('subflow_pinned_version_missing', array_map(static fn ($error): string => $error->code, $result->errors));
    }

    private function probe(): Connection
    {
        $probe = DB::connection(self::PROBE);
        $probe->statement("SET lock_timeout = '2s'");

        return $probe;
    }

    /**
     * Publishes versions 1…$count of a new flow, the highest active, committed.
     */
    private function publish(int $count): string
    {
        $flowId          = (string) Str::uuid();
        $this->flowIds[] = $flowId;

        for ($version = 1; $version <= $count; ++$version) {
            FlowDefinition::query()->create([
                'tenant_id' => $this->tenantId,
                'flow_id'   => $flowId,
                'version'   => $version,
                'name'      => 'Pinned',
                'nodes'     => [['id' => 'end', 'type' => 'end', 'version' => 1, 'config' => ['status' => 'success']]],
                'edges'     => [],
                'is_active' => $count === $version,
            ]);
        }

        return $flowId;
    }

    private function definition(string $flowId, int $version): FlowDefinition
    {
        return FlowDefinition::query()->where('flow_id', $flowId)->where('version', $version)->firstOrFail();
    }
}
