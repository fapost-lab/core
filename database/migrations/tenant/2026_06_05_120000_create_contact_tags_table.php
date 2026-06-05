<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('contact_tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('contact_id')->constrained()->cascadeOnDelete();
            $table->string('tag', 255);
            // Polymorphic source: a flow_session_id or a staff user id. Nullable
            // because tags may be applied by automated paths without an actor.
            $table->uuid('tagged_by')->nullable();
            $table->timestamp('tagged_at');

            $table->unique(['contact_id', 'tag'], 'contact_tags_contact_tag_unique');
            $table->index('tag', 'contact_tags_tag_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_tags');
    }
};
