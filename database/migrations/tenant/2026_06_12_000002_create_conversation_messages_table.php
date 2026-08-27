<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        if ('pgsql' === Schema::getConnection()->getDriverName()) {
            $this->createPartitioned();

            return;
        }

        // Non-partitioned fallback (sqlite test connection, other drivers).
        Schema::create('conversation_messages', function (Blueprint $table): void {
            $this->columns($table);
            $table->primary('id');
            $this->indexes($table);
        });
    }

    public function down(): void
    {
        if ('pgsql' === Schema::getConnection()->getDriverName()) {
            DB::statement('DROP TABLE IF EXISTS conversation_messages CASCADE');

            return;
        }

        Schema::dropIfExists('conversation_messages');
    }

    private function createPartitioned(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE conversation_messages (
    id uuid NOT NULL,
    tenant_id uuid NOT NULL,
    conversation_id uuid NOT NULL,
    contact_id uuid NOT NULL,
    assistant_id uuid NOT NULL,
    channel_id uuid NOT NULL,
    direction varchar(16) NOT NULL,
    sender_type varchar(16) NOT NULL,
    sender_staff_user_id uuid NULL,
    content_type varchar(32) NOT NULL,
    text text NULL,
    payload jsonb NULL,
    media jsonb NULL,
    provider_message_id varchar(255) NULL,
    reply_to_provider_message_id varchar(255) NULL,
    status varchar(16) NOT NULL,
    error jsonb NULL,
    origin varchar(16) NOT NULL,
    origin_ref jsonb NULL,
    idempotency_key varchar(255) NULL,
    created_at timestamp with time zone NOT NULL,
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at)
SQL);

        DB::statement('CREATE INDEX conversation_messages_thread_idx ON conversation_messages (conversation_id, created_at)');
        DB::statement('CREATE INDEX conversation_messages_contact_idx ON conversation_messages (tenant_id, contact_id, created_at)');
        DB::statement('CREATE INDEX conversation_messages_provider_idx ON conversation_messages (provider_message_id)');

        // Idempotency: partition-key column is included because Postgres requires
        // unique indexes on partitioned tables to carry the partition key. Retries
        // replay the same serialized entry (identical created_at), so duplicates
        // still collide; distinct NULL idempotency_key rows insert freely.
        DB::statement('CREATE UNIQUE INDEX conversation_messages_idem_unique ON conversation_messages (conversation_id, direction, idempotency_key, created_at)');

        $start = Carbon::now('UTC')->startOfMonth();
        for ($i = 0; $i < 3; $i++) {
            $month      = $start->copy()->addMonths($i);
            $monthStart = $month->copy()->startOfMonth()->format('Y-m-d H:i:sP');
            $monthEnd   = $month->copy()->addMonth()->startOfMonth()->format('Y-m-d H:i:sP');
            $suffix     = $month->format('Y_m');
            DB::statement("CREATE TABLE IF NOT EXISTS conversation_messages_{$suffix} PARTITION OF conversation_messages FOR VALUES FROM ('{$monthStart}') TO ('{$monthEnd}')");
        }
    }

    private function columns(Blueprint $table): void
    {
        $table->uuid('id');
        $table->uuid('tenant_id');
        $table->uuid('conversation_id');
        $table->uuid('contact_id');
        $table->uuid('assistant_id');
        $table->uuid('channel_id');
        $table->string('direction', 16);
        $table->string('sender_type', 16);
        $table->uuid('sender_staff_user_id')->nullable();
        $table->string('content_type', 32);
        $table->text('text')->nullable();
        $table->jsonb('payload')->nullable();
        $table->jsonb('media')->nullable();
        $table->string('provider_message_id')->nullable();
        $table->string('reply_to_provider_message_id')->nullable();
        $table->string('status', 16);
        $table->jsonb('error')->nullable();
        $table->string('origin', 16);
        $table->jsonb('origin_ref')->nullable();
        $table->string('idempotency_key')->nullable();
        $table->timestampTz('created_at');
    }

    private function indexes(Blueprint $table): void
    {
        $table->index(['conversation_id', 'created_at'], 'conversation_messages_thread_idx');
        $table->index(['tenant_id', 'contact_id', 'created_at'], 'conversation_messages_contact_idx');
        $table->index('provider_message_id', 'conversation_messages_provider_idx');
        $table->unique(
            ['conversation_id', 'direction', 'idempotency_key', 'created_at'],
            'conversation_messages_idem_unique',
        );
    }
};
