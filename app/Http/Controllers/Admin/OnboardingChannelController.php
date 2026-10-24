<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\AssistantChannelService;
use App\Domains\Onboarding\Enums\CoreOnboardingStep;
use App\Domains\Onboarding\Services\OnboardingState;
use App\Domains\Onboarding\Services\OnboardingWizard;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\AccessibleAssistants;
use App\Http\Controllers\Concerns\PresentsChannels;
use App\Http\Controllers\Concerns\RedirectsWizard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OnboardingChannelRequest;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The optional channel step of the setup wizard (`admin/onboarding/channel`): connect a bot to the first assistant the
 * user may open, with the channel form of the console and {@see AssistantChannelService}.
 *
 * Props of `Console/Onboarding/Channel`:
 *   onboarding: { steps: list<{key: string, required: bool, complete: bool, current: bool}> }
 *   types, settings: what the channel form offers (see {@see PresentsChannels::channelFormOptions()})
 *   later:      string   where to do this later, worded with the labels of the menu
 *   urls:       { submit: string, skip: string }
 *
 * The step runs after the required ones, also for a tenant that is no longer in the wizard; while a required step is
 * open the person is sent back to the wizard. Without an assistant to connect it to, without the right to create a
 * channel, or at the channel limit, the step is not shown and the wizard goes on (the store answers 403 without the right). Secrets never reach the browser: the token is only ever sent to the store.
 * Skipping is {@see OnboardingController::continue()} and writes nothing.
 */
final class OnboardingChannelController extends Controller
{
    use PresentsChannels;
    use RedirectsWizard;

    private const string STEP = 'channel';

    public function __construct(
        private readonly AccessibleAssistants $assistants,
        private readonly AssistantChannelService $channels,
        private readonly OnboardingState $state,
        private readonly OnboardingWizard $wizard,
    ) {
    }

    public function create(Request $request): Response|RedirectResponse
    {
        if ($this->state->isPending()) {
            return redirect()->route('console.admin.onboarding.show');
        }

        $assistant = $this->assistant($request);

        if (null === $assistant) {
            return $this->redirectOnward(self::STEP);
        }

        // The wizard sends everyone who finished the required steps here; a user who may not connect a channel is
        // passed on, as at the limit. The store still refuses them.
        if (Gate::denies('create', [Channel::class, $assistant]) || $this->channels->limit()->reached) {
            return $this->redirectOnward(self::STEP);
        }

        return Inertia::render('Console/Onboarding/Channel', [
            'onboarding' => ['steps' => $this->wizard->stepProps(CoreOnboardingStep::Channel->value)],
            ...$this->channelFormOptions(),
            'later' => trans('console.onboarding.channel.later', [
                'path' => trans('assistant.navigation.groups.channels') . ' › ' . trans('staff.channels.plural_label'),
            ]),
            'urls' => [
                'submit' => route('console.admin.onboarding.channel.store', [], false),
                'skip'   => route('console.admin.onboarding.continue', ['step' => self::STEP], false),
            ],
        ]);
    }

    public function store(OnboardingChannelRequest $request): RedirectResponse
    {
        if ($this->state->isPending()) {
            return redirect()->route('console.admin.onboarding.show');
        }

        $assistant = $this->assistant($request);

        if (null === $assistant) {
            return $this->redirectOnward(self::STEP);
        }

        try {
            $channel = $this->channels->create($assistant, $request->fields());
        } catch (RecordLimitReachedException $exception) {
            Inertia::flash('error', trans('console.channels.limit_reached') . '. ' . $exception->getMessage());

            return $this->redirectOnward(self::STEP, false);
        }

        // The provider's refusal is worded from the stored outcome with a fixed text, never from an exception: its
        // message can carry the request URL, and with it the bot's token.
        if ($channel->webhookRegistrationFailed()) {
            Inertia::flash('error', trans('console.channels.provider_failed.saved'));
        } else {
            Inertia::flash('success', trans('console.channels.created'));
        }

        return $this->redirectOnward(self::STEP, false);
    }

    private function assistant(Request $request): ?Assistant
    {
        /** @var User $user */
        $user = $request->user();

        return $this->assistants->for($user)->first();
    }
}
