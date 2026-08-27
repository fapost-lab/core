<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('contact_group_members', function (Blueprint $table): void {
            $table->foreignUuid('contact_group_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained()->cascadeOnDelete();

            // Composite PK covers "contacts in group X" lookups (leftmost prefix
            // on contact_group_id); the extra index covers the reverse
            // "groups for contact Y" lookup used when rendering a contact.
            $table->primary(['contact_group_id', 'contact_id']);
            $table->index(['contact_id', 'contact_group_id'], 'contact_group_members_contact_group_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_group_members');
    }
};
