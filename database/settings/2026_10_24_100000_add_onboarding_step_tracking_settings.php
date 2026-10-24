<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class () extends SettingsMigration {
    /**
     * `known_steps` is a literal snapshot of the keys Core's wizard has when this ships: every tenant that exists when
     * this runs finished onboarding with exactly these steps, so a required step registered later is a late one for it.
     * The list is deliberately not read from the registry (a migration makes no runtime decision) and never changes:
     * a step added to Core afterwards is, by definition, not known to these tenants. A tenant provisioned after this
     * runs has the list cleared when its wizard starts and filled again when it closes.
     *
     * @throws Spatie\LaravelSettings\Exceptions\SettingAlreadyExists
     */
    public function up(): void
    {
        $this->migrator->add('onboarding.known_steps', ['languages', 'first_assistant']);
        $this->migrator->add('onboarding_announcements.announced_steps', []);
    }
};
