<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Assistant\Support\ContentLanguages;
use App\Domains\Flow\Services\TenantSettingsEditor;
use App\Domains\Staff\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TenantSettingsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's settings in the admin panel, inside the console shell's admin mode: one form in three tabs (languages,
 * runtime, broadcasts) saved as a whole, with the permission and the rules of the Filament page it replaces.
 *
 * These are the tenant's own runtime settings ({@see TenantSettingsEditor}), not the plan quotas an operator package
 * answers through the quota contracts.
 */
final class TenantSettingsController extends Controller
{
    public function __construct(
        private readonly TenantSettingsEditor $editor,
    ) {
    }

    public function edit(): Response
    {
        Gate::authorize(Permission::ManageSettings->value);

        $languages = [];

        foreach (ContentLanguages::options() as $value => $label) {
            $languages[] = ['value' => (string) $value, 'label' => $label];
        }

        return Inertia::render('Console/TenantSettings/Edit', [
            'settings'           => $this->editor->state(),
            'baseLanguageLocked' => $this->editor->baseLanguageLocked(),
            'options'            => ['languages' => $languages],
            'urls'               => ['submit' => route('console.admin.tenant-settings.update', [], false)],
        ]);
    }

    public function update(TenantSettingsRequest $request): RedirectResponse
    {
        $this->editor->save($request->fields());

        Inertia::flash('success', trans('console.tenant_settings.saved'));

        return redirect()->back(fallback: route('filament.admin.pages.tenant-settings', [], false));
    }
}
