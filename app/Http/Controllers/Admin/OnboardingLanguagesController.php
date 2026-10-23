<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Assistant\Support\ContentLanguages;
use App\Domains\Flow\Services\TenantSettingsEditor;
use App\Domains\Onboarding\Enums\CoreOnboardingStep;
use App\Domains\Onboarding\Services\OnboardingState;
use App\Domains\Onboarding\Services\OnboardingWizard;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Tenancy\Settings\TenantSettings;
use App\Http\Controllers\Concerns\RedirectsWizard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OnboardingLanguagesRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The content languages step of the setup wizard (`admin/onboarding/languages`): the base language, the available
 * languages and the fallback, with the rules and the base-language lock of the tenant settings screen.
 *
 * Props of `Console/Onboarding/Languages`:
 *   onboarding:         { steps: list<{key: string, required: bool, complete: bool, current: bool}> }
 *   languages:          { base: string, available: list<string>, fallback: string }
 *   options:            list<{value: string, label: string}>   the content-language catalogue
 *   baseLanguageLocked: bool   the tenant already has a flow; the base is shown read-only and cannot change
 *   updateUrl:          string
 *
 * Submitting writes the three fields (the base only while unlocked), confirms the step and lets the wizard move on.
 */
final class OnboardingLanguagesController extends Controller
{
    use RedirectsWizard;

    public function __construct(
        private readonly TenantSettingsEditor $editor,
        private readonly TenantSettings $settings,
        private readonly OnboardingState $state,
        private readonly OnboardingWizard $wizard,
    ) {
    }

    public function edit(): Response|RedirectResponse
    {
        Gate::authorize(Permission::ManageSettings->value);

        if (! $this->state->isPending()) {
            return redirect()->route('console.admin.dashboard');
        }

        $options = [];

        foreach (ContentLanguages::options() as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        return Inertia::render('Console/Onboarding/Languages', [
            'onboarding' => ['steps' => $this->wizard->stepProps()],
            'languages'  => [
                'base'      => $this->settings->content_base_language,
                'available' => array_values($this->settings->available_languages),
                'fallback'  => $this->settings->fallback_language,
            ],
            'options'            => $options,
            'baseLanguageLocked' => $this->editor->baseLanguageLocked(),
            'updateUrl'          => route('console.admin.onboarding.languages.update', [], false),
        ]);
    }

    public function update(OnboardingLanguagesRequest $request): RedirectResponse
    {
        if (! $this->state->isPending()) {
            return redirect()->route('console.admin.dashboard');
        }

        $languages = $request->languages();

        $this->editor->saveLanguages($languages['base'], $languages['available'], $languages['fallback']);
        $this->state->confirm(CoreOnboardingStep::Languages->value);

        return $this->redirectFor($this->wizard->advance());
    }
}
