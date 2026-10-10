<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Services\StaffAssistantService;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\AssistantChannelService;
use App\Domains\Channels\Services\ChannelWebhookSyncOutcome;
use App\Domains\Staff\Models\User;
use App\Http\Controllers\Concerns\PresentsChannels;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssistantChannelRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * An assistant's channels from the admin panel, as the Filament relation manager offered them: changing a channel on
 * the console's channel form, registering its webhook again, rotating its webhook hash and deleting it. Creating a
 * channel stays on the assistant's console, as it was.
 *
 * The assistant comes from the URL (`{record}`) through {@see StaffAssistantService} (one the user may not see is a
 * 404) and must be viewable; the channel, found inside that assistant, answers to the channel policy. The rules about
 * secrets and provider refusals are the console's ({@see \App\Http\Controllers\Console\ChannelController}): no token
 * reaches the browser, and a refusal is worded from the stored outcome, never from an exception.
 */
final class AssistantChannelController extends Controller
{
    use PresentsChannels;

    public function __construct(
        private readonly StaffAssistantService $assistants,
        private readonly AssistantChannelService $channels,
        private readonly ChannelWebhookSyncOutcome $outcome,
    ) {
    }

    public function edit(Request $request, string $record, string $channel): Response
    {
        $assistant = $this->assistant($request, $record);
        $found     = $this->channels->findFor($assistant, $channel);

        Gate::authorize('update', $found);

        return Inertia::render('Console/Channels/Edit', [
            'channel' => $this->editableChannel($found, $this->url('register-webhook', $assistant, $found)),
            ...$this->channelFormOptions(),
            'urls' => [
                'index'  => $this->showUrl($assistant),
                'submit' => $this->url('update', $assistant, $found),
            ],
        ]);
    }

    public function update(AssistantChannelRequest $request, string $record, string $channel): RedirectResponse
    {
        $assistant = $this->assistant($request, $record);
        $updated   = $this->channels->update($this->channels->findFor($assistant, $channel), $request->fields());

        // Switching a channel off deregisters its webhook, which is another thing to check than a rejected token.
        if ($this->outcome->deregisterFailed((string) $updated->getKey())) {
            return $this->toAssistant($assistant, trans('console.channels.provider_failed.deactivated'), 'error');
        }

        return $updated->webhookRegistrationFailed()
            ? $this->toAssistant($assistant, trans('console.channels.provider_failed.saved'), 'error')
            : $this->toAssistant($assistant, trans('console.channels.updated'));
    }

    public function rotateWebhook(Request $request, string $record, string $channel): RedirectResponse
    {
        // Rotating shows the new hash, so seeing channels is required too, not only the rotation permission.
        Gate::authorize('viewAny', Channel::class);

        $assistant = $this->assistant($request, $record);
        $found     = $this->channels->findFor($assistant, $channel);

        Gate::authorize('rotateWebhook', $found);

        $rotated = $this->channels->rotateWebhookHash($found);

        if ($rotated->webhookRegistrationFailed()) {
            return $this->backToList($assistant, trans('console.channels.provider_failed.rotated'), 'error');
        }

        // The new hash is shown, as the Filament action showed it; only someone who may rotate gets here.
        return $this->backToList($assistant, trans('console.channels.rotated', ['hash' => $rotated->webhook_public_hash]));
    }

    public function registerWebhook(Request $request, string $record, string $channel): RedirectResponse
    {
        $assistant = $this->assistant($request, $record);
        $found     = $this->channels->findFor($assistant, $channel);

        Gate::authorize('update', $found);

        if (! $found->is_active) {
            return $this->backToList($assistant, trans('console.channels.register_webhook.inactive'), 'error');
        }

        return $this->channels->reregisterWebhook($found)->webhookRegistrationFailed()
            ? $this->backToList($assistant, trans('console.channels.register_webhook.failed'), 'error')
            : $this->backToList($assistant, trans('console.channels.register_webhook.done'));
    }

    public function destroy(Request $request, string $record, string $channel): RedirectResponse
    {
        $assistant = $this->assistant($request, $record);
        $found     = $this->channels->findFor($assistant, $channel);

        Gate::authorize('delete', $found);

        $this->channels->delete($found);

        return $this->outcome->deregisterFailed((string) $found->getKey())
            ? $this->backToList($assistant, trans('console.channels.provider_failed.deleted'), 'error')
            : $this->backToList($assistant, trans('console.channels.deleted'));
    }

    /**
     * The assistant in the URL, which the user must be able to see (the relation manager lived on its view page).
     */
    private function assistant(Request $request, string $record): Assistant
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new LogicException('The admin stack runs for a signed-in staff user.');
        }

        $assistant = $this->assistants->findFor($user, $record);

        Gate::authorize('view', $assistant);

        return $assistant;
    }

    /**
     * After the form: the assistant's page, where its channels are listed.
     *
     * @param  'success'|'error'  $kind
     */
    private function toAssistant(Assistant $assistant, string $message, string $kind = 'success'): RedirectResponse
    {
        Inertia::flash($kind, $message);

        return redirect()->to($this->showUrl($assistant));
    }

    /**
     * After a change made from the list: the page the user was on (with the list's sort and page), or the assistant's.
     *
     * @param  'success'|'error'  $kind
     */
    private function backToList(Assistant $assistant, string $message, string $kind = 'success'): RedirectResponse
    {
        Inertia::flash($kind, $message);

        return redirect()->back(fallback: $this->showUrl($assistant));
    }

    private function showUrl(Assistant $assistant): string
    {
        return route('filament.admin.resources.assistants.view', ['record' => $assistant->getKey()], false);
    }

    private function url(string $action, Assistant $assistant, Channel $channel): string
    {
        return route('console.admin.assistants.channels.' . $action, ['record' => $assistant->getKey(), 'channel' => $channel->getKey()], false);
    }
}
