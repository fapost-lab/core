<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Logging;

use App\Domains\Flow\Logging\DatabaseAnalyticsWriter;
use DateTimeImmutable;
use Fapost\Foundation\Analytics\DTO\AnalyticsEvent;
use Fapost\Foundation\Analytics\Enums\AnalyticsEventType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

final class DatabaseAnalyticsWriterTest extends FeatureTestCase
{
    public function test_it_records_analytics_after_commit(): void
    {
        $writer   = $this->app->make(DatabaseAnalyticsWriter::class);
        $tenantId = (string) Str::uuid();

        DB::transaction(function () use ($writer, $tenantId): void {
            DB::afterCommit(function () use ($writer, $tenantId): void {
                $writer->record(new AnalyticsEvent(
                    tenantId: $tenantId,
                    eventType: AnalyticsEventType::FlowStarted,
                    payload: ['session_id' => 'session-1'],
                    occurredAt: new DateTimeImmutable('2026-04-21 00:00:00'),
                ));
            });

            $this->assertDatabaseCount('analytics_events', 0);
        });

        $this->assertDatabaseHas('analytics_events', [
            'tenant_id'  => $tenantId,
            'event_type' => 'flow_started',
        ]);
    }
}
