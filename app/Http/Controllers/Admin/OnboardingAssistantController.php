<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Services\StaffAssistantService;
use App\Domains\Assistant\Support\ContentLanguages;
use App\Domains\Flow\Services\TenantSettingsEditor;
use App\Domains\Onboarding\Enums\CoreOnboardingStep;
use App\Domains\Onboarding\Services\OnboardingState;
use App\Domains\Onboarding\Services\OnboardingWizard;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Settings\TenantSettings;
use App\Http\Controllers\Concerns\RedirectsWizard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OnboardingAssistantRequest;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first assistant step of the setup wizard (`admin/onboarding/assistant`): a name and a language, created through
 * {@see StaffAssistantService} like the assistants screen does.
 *
 * Props of `Console/Onboarding/Assistant`:
 *   onboarding:      { steps: list<{key: string, required: bool, complete: bool, current: bool}> }
 *   languageOptions: list<{value: string, label: string}>   the tenant's available languages, the base language always among them
 *   defaultLanguage: string                                  the tenant's content base language
 *   storeUrl:        string
 *   backUrl:         string|null                             the previous required step, the languages
 *
 * A tenant that already has an assistant does not see the page: the step is done by the data, and the wizard moves on.
 * After the wizard the checklist still links here, so a finished wizard sends the person to the assistants list.
 * Creating the assistant closes the wizard when it was the last open required step; the person then goes on to the
 * optional steps that are still open, and from the last of them to the assistant's console.
 */
final class OnboardingAssistantController extends Controller
{
    use RedirectsWizard;

    public function __construct(
        private readonly StaffAssistantService $assistants,
        private readonly TenantSettings $settings,
        private readonly OnboardingState $state,
        private readonly OnboardingWizard $wizard,
    ) {
    }

    public function create(): Response|RedirectResponse
    {
        Gate::authorize('create', Assistant::class);

        if (! $this->state->isPending()) {
            return redirect()->route('console.admin.assistants.index');
        }

        if (Assistant::query()->exists()) {
            return redirect()->route('console.admin.onboarding.show');
        }

        $catalogue = ContentLanguages::options();
        $options   = [];

        // The base language is offered even when stored settings left it out of the available ones.
        foreach (TenantSettingsEditor::withBase($this->settings->content_base_language, $this->settings->available_languages) as $code) {
            $options[] = ['value' => $code, 'label' => $catalogue[$code] ?? $code];
        }

        return Inertia::render('Console/Onboarding/Assistant', [
            'onboarding'      => ['steps' => $this->wizard->stepProps()],
            'languageOptions' => $options,
            'defaultLanguage' => $this->settings->content_base_language,
            'storeUrl'        => route('console.admin.onboarding.assistant.store', [], false),
            'backUrl'         => $this->backUrl(CoreOnboardingStep::FirstAssistant->value),
        ]);
    }

    public function store(OnboardingAssistantRequest $request): RedirectResponse
    {
        if (! $this->state->isPending()) {
            return redirect()->route('console.admin.dashboard');
        }

        /** @var User $user */
        $user = $request->user();

        try {
            $this->assistants->create($user, $request->fields());
        } catch (RecordLimitReachedException $exception) {
            Inertia::flash('error', trans('console.assistants.limit_reached') . '. ' . $exception->getMessage());

            return redirect()->route('console.admin.onboarding.show');
        }

        return $this->redirectFor($this->wizard->advance());
    }
}
