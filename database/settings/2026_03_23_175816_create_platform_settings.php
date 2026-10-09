<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class () extends SettingsMigration {
    /**
     * @throws Spatie\LaravelSettings\Exceptions\SettingAlreadyExists
     */
    public function up(): void
    {
        $this->migrator->add('platform.version', '1.0.0');
        $this->migrator->add('platform.maintenance_mode', false);
        $this->migrator->add('platform.default_locale', 'en');
        $this->migrator->add('platform.default_timezone', 'UTC');
    }
};
