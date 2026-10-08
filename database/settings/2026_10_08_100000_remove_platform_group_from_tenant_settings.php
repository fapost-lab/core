<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class () extends SettingsMigration {
    /**
     * The `platform` group was never read, and it looked platform-wide while living in every
     * tenant's `settings` table. Platform-wide settings belong to the operator package.
     */
    public function up(): void
    {
        $this->migrator->deleteIfExists('platform.version');
        $this->migrator->deleteIfExists('platform.maintenance_mode');
        $this->migrator->deleteIfExists('platform.default_locale');
        $this->migrator->deleteIfExists('platform.default_timezone');
    }
};
