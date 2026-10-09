<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\AssistantChannelService;
use App\Domains\Channels\Telegram\Exceptions\TelegramApiException;
use App\Domains\Channels\Telegram\TelegramWebhookOptions;
use App\Http\Controllers\Controller;
use App\Http\DataTable\DataTable;
use App\Http\Requests\Console\ChannelRequest;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Channels on the Inertia console: the assistant's list, the form for a channel, rotating its webhook hash and
 * deleting it.
 *
 * {@see AssistantChannelService} bounds every read by the tenant and the assistant in the URL; the writes are the
 * channel domain's, which checks the channel limit and keeps the webhook routing and the provider's webhook in step.
 *
 * Secrets never reach the browser: a channel's token and secret token are not in any prop, and the edit form shows
 * them empty (an empty field keeps what is stored). The webhook hash is shown on the edit page and, once, after a
 * rotation, to the one who may rotate.
 *
 * The provider is called while the channel is saved, so it may refuse after the record is already stored. That is
 * worded as a toast with a fixed text: the exception's message can carry the request URL, and with it the bot's token.
 * Only Telegram's exception is known here; a driver that throws its own gets a server error until it is added.
 */
final class ChannelController extends Controller
{
    public function __construct(
        private readonly AssistantChannelService $channels,
        private readonly CurrentAssistantInterface $assistant,
    ) {
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Channel::class);

        $assistant = $this->assistant->get();
        $limit     = $this->channels->limit();
        $probe     = $this->channels->abilityProbe($assistant);
        $table     = new DataTable(
            sortable: ['type', 'is_active', 'updated_at'],
            searchable: [],
            defaultSort: '-updated_at',
        );

        return Inertia::render('Console/Channels/Index', [
            'table' => $table->respond(
                $request,
                $this->channels->query($assistant),
                fn (Channel $channel): array => [
                    'id'        => (string) $channel->getKey(),
                    'type'      => $channel->type->value,
                    'typeLabel' => trans($channel->type->labelKey()),
                    'handle'    => $channel->publicHandle(),
                    'url'       => $channel->publicUrl(),
                    'isActive'  => $channel->is_active,
                    'updatedAt' => $channel->updated_at?->toIso8601String(),
                    'editUrl'   => $this->url('edit', ['record' => $channel->getKey()]),
                    'deleteUrl' => $this->url('destroy', ['record' => $channel->getKey()]),
                    'rotateUrl' => $this->url('rotate-webhook', ['record' => $channel->getKey()]),
                ],
            ),
            'limit' => [
                'reached' => $limit->reached,
                'hint'    => $limit->reached && null !== $limit->limit
                    ? trans('staff.channels.limit.hint', ['current' => $limit->current, 'limit' => $limit->limit])
                    : null,
            ],
            'can' => [
                // The policy lets an administrator through whatever the limit says, so the limit is asked on its own.
                'create' => Gate::allows('create', [Channel::class, $assistant]) && ! $limit->reached,
                'update' => Gate::allows('update', $probe),
                'delete' => Gate::allows('delete', $probe),
                'rotate' => Gate::allows('rotateWebhook', $probe),
            ],
            'urls' => [
                'index'  => $this->url('index'),
                'create' => $this->url('create'),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', [Channel::class, $this->assistant->get()]);

        // The page is closed at the limit, but only on opening: a form opened below it that is saved after another
        // request took the last slot still reaches the service, which refuses with a message.
        abort_if($this->channels->limit()->reached, HttpResponse::HTTP_FORBIDDEN);

        return Inertia::render('Console/Channels/Create', [
            ...$this->options(),
            'urls' => [
                'index'  => $this->url('index'),
                'submit' => $this->url('store'),
            ],
        ]);
    }

    public function store(ChannelRequest $request): RedirectResponse
    {
        $assistant = $this->assistant->get();
        $index     = $this->url('index');

        try {
            $this->channels->create($assistant, $request->fields());
        } catch (RecordLimitReachedException $exception) {
            Inertia::flash('error', trans('console.channels.limit_reached') . '. ' . $exception->getMessage());

            return redirect()->back(fallback: $index);
        } catch (TelegramApiException $exception) {
            return $this->providerRefused($exception, 'saved', $index);
        }

        return $this->backToIndex(trans('console.channels.created'), $index);
    }

    /*
     * Route parameters reach an action by position, not by name, so `$tenant` (the assistant in the URL, already
     * resolved by the console stack) has to be declared ahead of `$record`.
     */
    public function edit(string $tenant, string $record): Response
    {
        $channel = $this->channels->findFor($this->assistant->get(), $record);

        Gate::authorize('update', $channel);

        $isTelegram = ChannelTypeEnum::Telegram === $channel->type;
        $config     = is_array($channel->config) ? $channel->config : [];

        return Inertia::render('Console/Channels/Edit', [
            'channel' => [
                'id'          => (string) $channel->getKey(),
                'type'        => $channel->type->value,
                'typeLabel'   => trans($channel->type->labelKey()),
                'isActive'    => $channel->is_active,
                'webhookHash' => $channel->webhook_public_hash,
                'handle'      => $channel->publicHandle(),
                'url'         => $channel->publicUrl(),
                'telegram'    => $isTelegram ? [
                    'allowedUpdates' => $this->allowedUpdates($config),
                    'maxConnections' => is_numeric($config['max_connections'] ?? null)
                        ? (int) $config['max_connections']
                        : TelegramWebhookOptions::MAX_CONNECTIONS_DEFAULT,
                ] : null,
                'configEntries' => $isTelegram ? null : $this->entries($config),
            ],
            ...$this->options(),
            'urls' => [
                'index'  => $this->url('index'),
                'submit' => $this->url('update', ['record' => $channel->getKey()]),
            ],
        ]);
    }

    public function update(ChannelRequest $request, string $tenant, string $record): RedirectResponse
    {
        $channel = $this->channels->findFor($this->assistant->get(), $record);
        $index   = $this->url('index');

        $fields = $request->fields();

        try {
            $this->channels->update($channel, $fields);
        } catch (TelegramApiException $exception) {
            // Switching a channel off deregisters its webhook, which is another thing to check than a rejected token.
            return $this->providerRefused($exception, false === $fields['is_active'] ? 'deactivated' : 'saved', $index);
        }

        return $this->backToIndex(trans('console.channels.updated'), $index);
    }

    public function rotateWebhook(string $tenant, string $record): RedirectResponse
    {
        // Rotating shows the new hash, so seeing channels is required too, not only the rotation permission.
        Gate::authorize('viewAny', Channel::class);

        $channel = $this->channels->findFor($this->assistant->get(), $record);

        Gate::authorize('rotateWebhook', $channel);

        $index = $this->url('index');

        try {
            $rotated = $this->channels->rotateWebhookHash($channel);
        } catch (TelegramApiException $exception) {
            return $this->providerRefused($exception, 'rotated', $index, backToList: true);
        }

        // The new hash is shown, as the Filament action showed it; only someone who may rotate gets here.
        return $this->backToList(trans('console.channels.rotated', ['hash' => $rotated->webhook_public_hash]), $index);
    }

    public function destroy(string $tenant, string $record): RedirectResponse
    {
        $channel = $this->channels->findFor($this->assistant->get(), $record);

        Gate::authorize('delete', $channel);

        $index = $this->url('index');

        try {
            $this->channels->delete($channel);
        } catch (TelegramApiException $exception) {
            return $this->providerRefused($exception, 'deleted', $index, backToList: true);
        }

        return $this->backToList(trans('console.channels.deleted'), $index);
    }

    /**
     * The provider refused while the change was being made. The change is stored (the provider is called after the
     * commit), so the user is told what stands and what to check; the exception is reported, never shown.
     *
     * @param  'saved'|'deactivated'|'rotated'|'deleted'  $what
     */
    private function providerRefused(TelegramApiException $exception, string $what, string $index, bool $backToList = false): RedirectResponse
    {
        report($exception);

        return $backToList
            ? $this->backToList(trans("console.channels.provider_failed.{$what}"), $index, 'error')
            : $this->backToIndex(trans("console.channels.provider_failed.{$what}"), $index, 'error');
    }

    /**
     * What the form offers: the channel types and the Telegram update types, labelled in the interface language
     * from the labels the staff screens use, and the bounds of the delivery parallelism.
     *
     * @return array{types: list<array{value: string, label: string}>, telegramUpdates: list<array{value: string, label: string}>, maxConnections: array{min: int, max: int, default: int}}
     */
    private function options(): array
    {
        $types = [];

        foreach (ChannelTypeEnum::cases() as $type) {
            $types[] = ['value' => $type->value, 'label' => trans($type->labelKey())];
        }

        $updates = [];

        foreach (TelegramWebhookOptions::ALLOWED_UPDATES as $update) {
            $key       = "staff.channels.telegram_updates.{$update}";
            $label     = trans($key);
            $updates[] = ['value' => $update, 'label' => $label === $key ? Str::headline($update) : $label];
        }

        return [
            'types'           => $types,
            'telegramUpdates' => $updates,
            'maxConnections'  => [
                'min'     => TelegramWebhookOptions::MAX_CONNECTIONS_MIN,
                'max'     => TelegramWebhookOptions::MAX_CONNECTIONS_MAX,
                'default' => TelegramWebhookOptions::MAX_CONNECTIONS_DEFAULT,
            ],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $config
     *
     * @return list<string>
     */
    private function allowedUpdates(array $config): array
    {
        $updates = is_array($config['allowed_updates'] ?? null) ? $config['allowed_updates'] : [];

        // Only types the form offers: a stored one outside the list would fail validation with nothing to correct.
        return array_values(array_filter($updates, static fn (mixed $update): bool => is_string($update) && in_array($update, TelegramWebhookOptions::ALLOWED_UPDATES, true)));
    }

    /**
     * The settings of a channel without named ones, as the form lists them. Only scalar values can be shown; any
     * other is left out (and is dropped when the form is saved, as the Filament form dropped it).
     *
     * @param  array<array-key, mixed>  $config
     *
     * @return list<array{key: string, value: string}>
     */
    private function entries(array $config): array
    {
        $entries = [];

        foreach ($config as $key => $value) {
            if (is_scalar($value)) {
                $entries[] = ['key' => (string) $key, 'value' => (string) $value];
            }
        }

        return $entries;
    }

    /**
     * After a form: the list, as it opens by default. The message is Inertia flash data, so it reaches the toast once
     * and is not kept in the browser's history.
     *
     * The list's URL is taken before the write and passed in: saving a channel runs the provider's webhook job inside
     * the request, and switching tenants for it resets the current assistant, so nothing after a write may ask for it.
     *
     * @param  'success'|'error'  $kind
     */
    private function backToIndex(string $message, string $index, string $kind = 'success'): RedirectResponse
    {
        Inertia::flash($kind, $message);

        return redirect()->to($index);
    }

    /**
     * After a change made from the list: the list the user was on, with its sort and page (the referer), or the
     * default list when there is none.
     *
     * @param  'success'|'error'  $kind
     */
    private function backToList(string $message, string $index, string $kind = 'success'): RedirectResponse
    {
        Inertia::flash($kind, $message);

        return redirect()->back(fallback: $index);
    }

    /**
     * A relative URL of one of this screen's routes, inside the current assistant's console.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function url(string $action, array $parameters = []): string
    {
        $name = match ($action) {
            'index', 'create', 'edit' => 'filament.assistant.resources.channels.' . $action,
            default                   => 'console.channels.' . $action,
        };

        return route($name, ['tenant' => (string) $this->assistant->get()->getKey(), ...$parameters], false);
    }
}
