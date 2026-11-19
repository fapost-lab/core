<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The inbound address of a webhook trigger: one row per trigger id, made by the builder before the trigger is
 * published so the sender can be set up in advance. `token` is the unguessable path segment of
 * `/hooks/flow/{token}`; `secret` signs or authenticates the sender's requests and is kept here, encrypted with the
 * application key, never in the trigger's config (which is copied into drafts and every published definition).
 *
 * No foreign key to `flow_triggers`: the trigger's row exists only once it is published, and the endpoint must exist
 * before that.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('flow_webhook_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('flow_id');
            $table->uuid('trigger_id')->unique('flow_webhook_endpoints_trigger_unique');
            $table->string('token', 64)->unique('flow_webhook_endpoints_token_unique');
            $table->text('secret')->nullable();
            $table->timestamp('secret_rotated_at')->nullable();
            $table->timestamps();

            $table->index('flow_id', 'flow_webhook_endpoints_flow_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_webhook_endpoints');
    }
};
