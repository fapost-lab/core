<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Services\PlatformSettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlatformSettingsRequest;
use Fapost\Foundation\Settings\DTO\PlatformSettingDefinition;
use Fapost\Foundation\Settings\DTO\PlatformSettingValue;
use Fapost\Foundation\Settings\Exceptions\InvalidPlatformSettingsException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform-wide settings of a single-tenant deployment, in the admin panel: what the platform answers by default
 * and what an administrator has changed, grouped as the settings declare. Only for administrators of a deployment in
 * `single` mode (see {@see PlatformSettingsRequest::guard()}); in `host` mode the operator owns these settings.
 */
final class PlatformSettingsController extends Controller
{
    public function __construct(
        private readonly PlatformSettings $settings,
    ) {
    }

    public function edit(Request $request): Response
    {
        $user = $request->user();

        PlatformSettingsRequest::guard($user instanceof User ? $user : null);

        $values = [];

        foreach ($this->settings->values() as $value) {
            $values[$value->key] = $value;
        }

        $groups = [];

        foreach ($this->settings->definitions() as $definition) {
            if ($definition->hostModeOnly) {
                continue;
            }

            $groups[$definition->group][] = $this->present($definition, $values[$definition->key]);
        }

        $page = [];

        foreach ($groups as $name => $settings) {
            $page[] = ['name' => (string) $name, 'settings' => $settings];
        }

        return Inertia::render('Console/PlatformSettings/Edit', [
            'groups' => $page,
            'urls'   => ['submit' => route('console.admin.platform-settings.update', [], false)],
        ]);
    }

    public function update(PlatformSettingsRequest $request): RedirectResponse
    {
        try {
            $this->settings->update($request->changes(), 'user:' . $request->user()?->getKey());
        } catch (InvalidPlatformSettingsException $exception) {
            $errors = [];

            foreach ($exception->violations as $key => $message) {
                $errors[PlatformSettingsRequest::fieldName((string) $key)] = $message;
            }

            throw ValidationException::withMessages($errors);
        }

        Inertia::flash('success', trans('console.platform_settings.saved'));

        return redirect()->back(fallback: route('console.admin.platform-settings.edit', [], false));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PlatformSettingDefinition $definition, PlatformSettingValue $value): array
    {
        return [
            'key'         => $definition->key,
            'field'       => PlatformSettingsRequest::fieldName($definition->key),
            'type'        => $definition->type->value,
            'label'       => $definition->label,
            'description' => $definition->description,
            'unit'        => $definition->unit,
            'min'         => $definition->min,
            'max'         => $definition->max,
            'choices'     => $definition->choices,
            'default'     => $value->default,
            'stored'      => $value->stored,
            'value'       => $value->stored ? $value->value : null,
        ];
    }
}
