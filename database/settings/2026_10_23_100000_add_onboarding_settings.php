<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class () extends SettingsMigration {
    /**
     * Every tenant that exists when this runs is onboarded already, so the state starts as `complete`; only a tenant
     * provisioned afterwards is put into the wizard (TenantProvisioningService writes `pending`). That is why this
     * needs no backfill and no landlord record. The rows must exist all the same: spatie refuses to save a settings
     * class whose properties are not all stored.
     *
     * @throws Spatie\LaravelSettings\Exceptions\SettingAlreadyExists
     */
    public function up(): void
    {
        $this->migrator->add('onboarding.state', 'complete');
        $this->migrator->add('onboarding.confirmed_steps', []);
        $this->migrator->add('onboarding.started_at', null);
        $this->migrator->add('onboarding.completed_at', null);
    }
};
