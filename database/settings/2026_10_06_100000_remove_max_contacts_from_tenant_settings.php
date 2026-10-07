<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class () extends SettingsMigration {
    /**
     * `max_contacts` was never read. Limits are enforced through the Foundation quota contracts.
     */
    public function up(): void
    {
        $this->migrator->deleteIfExists('tenant.max_contacts');
    }
};
