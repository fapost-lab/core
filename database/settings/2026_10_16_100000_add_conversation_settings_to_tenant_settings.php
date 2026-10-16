<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class () extends SettingsMigration {
    /**
     * Both start unset (null = the install default from `CONVERSATION_LOGGING` / `CONVERSATION_MEDIA_FETCH`), so
     * nothing changes for an existing tenant until someone stores a value. The rows must exist all the same:
     * spatie refuses to save a settings class whose properties are not all stored, so without them the first save
     * of Tenant settings would fail.
     *
     * @throws Spatie\LaravelSettings\Exceptions\SettingAlreadyExists
     */
    public function up(): void
    {
        $this->migrator->add('tenant.conversation_logging', null);
        $this->migrator->add('tenant.conversation_media_fetch', null);
    }
};
