<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Widgets;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Logging\FlowLogEntry;
use App\Domains\Flow\Logging\FlowLogStatus;
use App\Domains\Flow\Logging\FlowLogWriter;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Filament\Widgets\FlowActivityChart;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Feature\FeatureTestCase;

/**
 * The widget's query ran PostgreSQL-only SQL (`DATE(created_at AT TIME ZONE 'UTC')`),
 * so it could never be exercised on the SQLite test connection before. These tests
 * run it for real and pin down the two behaviours the fix is about: the query must
 * run on SQLite at all, and a `terminal` log row (a normal flow completion, see
 * {@see \App\Domains\Flow\Services\FlowEngine::buildLogEntry()}) must not be counted
 * as a failure.
 */
final class FlowActivityChartTest extends FeatureTestCase
{
    private string $tenantId;

    private FlowSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();

        $assistant = Assistant::factory()->create(['tenant_id' => $this->tenantId]);
        $contact   = Contact::factory()->forTenant($this->tenantId)->create();

        $definition = FlowDefinition::query()->create([
            'tenant_id' => $this->tenantId,
            'flow_id'   => (string) Str::uuid(),
            'version'   => 1,
            'name'      => 'Chart',
            'nodes'     => [],
            'edges'     => [],
            'is_active' => true,
        ]);

        $this->session = FlowSession::query()->create([
            'tenant_id'          => $this->tenantId,
            'assistant_id'       => $assistant->getKey(),
            'contact_id'         => $contact->getKey(),
            'flow_definition_id' => $definition->getKey(),
            'flow_version'       => 1,
            'current_node_id'    => 'n1',
            'state'              => [],
            'status'             => 'active',
            'version'            => 1,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_it_runs_on_the_sqlite_test_connection(): void
    {
        // The bug this covers is a PostgreSQL-only `AT TIME ZONE` cast: the
        // regression is the query throwing on SQLite, so simply reaching an
        // assertion here is already proof the fix works.
        $data = $this->getData();

        $this->assertCount(14, $data['labels']);
        $this->assertCount(14, $data['datasets'][0]['data']);
        $this->assertCount(14, $data['datasets'][1]['data']);
    }

    public function test_executed_rows_are_counted_as_executed(): void
    {
        $today = CarbonImmutable::now();

        $this->writeLog(FlowLogStatus::Executed, $today);
        $this->writeLog(FlowLogStatus::Executed, $today);

        $data = $this->getData();

        $this->assertSame(2, $data['datasets'][0]['data'][13], 'both executed rows land in the executed bucket');
        $this->assertSame(0, $data['datasets'][1]['data'][13], 'none of them count as failed');
    }

    public function test_failed_rows_are_counted_as_failed(): void
    {
        $today = CarbonImmutable::now();

        $this->writeLog(FlowLogStatus::Failed, $today);

        $data = $this->getData();

        $this->assertSame(1, $data['datasets'][1]['data'][13]);
        $this->assertSame(0, $data['datasets'][0]['data'][13]);
    }

    public function test_terminal_rows_are_not_counted_as_failed(): void
    {
        $today = CarbonImmutable::now();

        // `terminal` is a normal flow completion, not a failure — this is the
        // second defect the task fixes: the old `default => failed` arm swept
        // it into the failed bucket.
        $this->writeLog(FlowLogStatus::Terminal, $today);

        $data = $this->getData();

        $this->assertSame(0, $data['datasets'][1]['data'][13], 'a terminal row must not be counted as a failure');
        $this->assertSame(1, $data['datasets'][0]['data'][13], 'it is counted as executed instead');
    }

    public function test_conflict_rows_are_counted_in_neither_bucket(): void
    {
        $today = CarbonImmutable::now();

        // `conflict` is a defined FlowLogStatus that FlowEngine never writes
        // today (see the widget's own comment). The widget deliberately
        // excludes it from both buckets rather than guessing.
        $this->writeLog(FlowLogStatus::Conflict, $today);

        $data = $this->getData();

        $this->assertSame(0, $data['datasets'][0]['data'][13]);
        $this->assertSame(0, $data['datasets'][1]['data'][13]);
    }

    public function test_rows_bucket_by_their_own_day(): void
    {
        $this->writeLog(FlowLogStatus::Executed, CarbonImmutable::now()->subDays(2));
        $this->writeLog(FlowLogStatus::Failed, CarbonImmutable::now()->subDays(1));

        $data = $this->getData();

        $this->assertSame(1, $data['datasets'][0]['data'][11], 'two days ago, index 11 of 14');
        $this->assertSame(1, $data['datasets'][1]['data'][12], 'yesterday, index 12 of 14');
        $this->assertSame(0, $data['datasets'][0]['data'][13]);
        $this->assertSame(0, $data['datasets'][1]['data'][13]);
    }

    public function test_day_buckets_do_not_shift_with_a_non_utc_session_timezone(): void
    {
        if ('pgsql' !== DB::connection()->getDriverName()) {
            $this->markTestSkipped('The session TimeZone this guards against exists on PostgreSQL only.');
        }

        // `created_at` is a timestamptz, so rendering it without an explicit
        // conversion follows the session's TimeZone — which an installation is
        // free to set. Late enough in the UTC day that a UTC+14 session renders
        // this row as tomorrow, which would drop it out of the window entirely.
        $lateInTheUtcDay = CarbonImmutable::now()->setTime(20, 0);

        $this->writeLog(FlowLogStatus::Executed, $lateInTheUtcDay);

        CarbonImmutable::setTestNow($lateInTheUtcDay);
        Carbon::setTestNow($lateInTheUtcDay);

        $originalTimeZone = (string) DB::selectOne('SHOW TimeZone')->TimeZone;

        DB::statement("SET TIME ZONE 'Pacific/Kiritimati'");

        try {
            $data = $this->getData();
        } finally {
            DB::statement("SET TIME ZONE '{$originalTimeZone}'");
        }

        $this->assertSame(1, $data['datasets'][0]['data'][13], 'the row stays on its own UTC day');
    }

    public function test_rows_older_than_the_fourteen_day_window_are_excluded(): void
    {
        $this->writeLog(FlowLogStatus::Executed, CarbonImmutable::now()->subDays(20));

        $data = $this->getData();

        $total = array_sum($data['datasets'][0]['data']) + array_sum($data['datasets'][1]['data']);
        $this->assertSame(0, $total, 'a row older than the 14-day window must not be counted');
    }

    /**
     * @return array<string, mixed>
     */
    private function getData(): array
    {
        $widget = new FlowActivityChart();

        $method = new ReflectionMethod($widget, 'getData');
        $method->setAccessible(true);

        /** @var array<string, mixed> $data */
        $data = $method->invoke($widget);

        return $data;
    }

    /**
     * Backdates through the real writer rather than a raw insert: on PostgreSQL
     * `flow_logs` is range-partitioned and only the months around today exist,
     * so a raw insert into an older month has no partition to land in. The
     * writer creates the partition for whatever `now()` says.
     */
    private function writeLog(FlowLogStatus $status, CarbonImmutable $at): void
    {
        CarbonImmutable::setTestNow($at);
        Carbon::setTestNow($at);

        try {
            $this->app->make(FlowLogWriter::class)->write(new FlowLogEntry(
                sessionId: (string) $this->session->getKey(),
                nodeId: 'n1',
                nodeType: 'test_node',
                nodeVersion: 1,
                status: $status,
                sourceHandle: null,
                stateChanges: null,
                resolved: null,
                error: null,
            ));
        } finally {
            CarbonImmutable::setTestNow();
            Carbon::setTestNow();
        }
    }
}
