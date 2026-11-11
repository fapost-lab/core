<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flow templates (task flow-templates): a template is a flow draft with `kind = template`, edited in the
 * builder like any flow but never started on its own. A host flow places it as one reference node, and
 * publishing the host expands the reference into a copy of the template's active definition.
 *
 * `kind` is set when the draft is created and never changes; the definition repeats it so the runtime can
 * refuse to start a template without reading the draft. `republished_by_template_*` say why a host has a
 * version its author did not publish: publishing a template republishes every flow that uses it.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('flow_drafts', function (Blueprint $table): void {
            $table->string('kind', 16)->default('flow');
            $table->index(['assistant_id', 'kind'], 'flow_drafts_assistant_kind_idx');
        });

        Schema::table('flow_definitions', function (Blueprint $table): void {
            $table->string('kind', 16)->default('flow');
            $table->uuid('republished_by_template_flow_id')->nullable();
            $table->integer('republished_by_template_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('flow_definitions', function (Blueprint $table): void {
            $table->dropColumn(['kind', 'republished_by_template_flow_id', 'republished_by_template_version']);
        });

        Schema::table('flow_drafts', function (Blueprint $table): void {
            $table->dropIndex('flow_drafts_assistant_kind_idx');
            $table->dropColumn('kind');
        });
    }
};
