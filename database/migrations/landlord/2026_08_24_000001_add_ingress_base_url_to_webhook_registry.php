<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    protected $connection = 'landlord';

    /**
     * Records which ingress host a channel's webhook was actually registered against.
     *
     * Two ingress runtimes can be live at once, and a channel keeps whatever URL the
     * provider stored at registration time. Deriving the host from configuration is
     * therefore wrong the moment the configured driver changes: it describes intent,
     * not what providers are calling. This column stores the fact.
     *
     * Existing rows stay NULL — the host they were registered against is genuinely
     * unknown here, and re-registration is what establishes it. Backfilling from
     * configuration would have to read runtime state, which migrations must not do,
     * and would record a guess as if it were fact.
     */
    public function up(): void
    {
        Schema::connection('landlord')->table('webhook_registry', function (Blueprint $table): void {
            $table->string('ingress_base_url')->nullable()->after('platform');

            $table->index('ingress_base_url');
        });
    }

    public function down(): void
    {
        Schema::connection('landlord')->table('webhook_registry', function (Blueprint $table): void {
            $table->dropIndex(['ingress_base_url']);
            $table->dropColumn('ingress_base_url');
        });
    }
};
