<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class () extends SettingsMigration {
    /**
     * @throws Spatie\LaravelSettings\Exceptions\SettingAlreadyExists
     */
    public function up(): void
    {
        $this->migrator->add('tenant.content_base_language', 'en');
        $this->migrator->add('tenant.available_languages', ['en']);
        $this->migrator->add('tenant.fallback_language', 'en');
    }
};
