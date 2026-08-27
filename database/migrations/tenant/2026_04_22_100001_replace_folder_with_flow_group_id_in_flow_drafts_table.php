<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('flow_drafts', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'folder']);
            $table->dropColumn('folder');
            $table->uuid('flow_group_id')->nullable()->after('assistant_id');
            $table->index(['tenant_id', 'flow_group_id']);
        });
    }

    public function down(): void
    {
        Schema::table('flow_drafts', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'flow_group_id']);
            $table->dropColumn('flow_group_id');
            $table->string('folder')->nullable()->after('assistant_id');
            $table->index(['tenant_id', 'folder']);
        });
    }
};
