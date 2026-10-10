<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Telegram\TelegramWebhookOptions;
use Closure;
use Illuminate\Support\Str;

/**
 * What the channel screens show of a channel, for every screen that lists or edits one: the assistant's console and
 * the tenant-wide admin. Each screen passes its own URLs; the shape of a row, of the edit form and of its options is
 * one.
 *
 * Secrets never reach the browser: neither the token nor the secret token is in a row or in the edit form.
 */
trait PresentsChannels
{
    /**
     * A row of a channel list.
     *
     * @param  Closure(string, array<string, mixed>): string  $url  a relative URL of one of the screen's actions (`edit`,
     *                                                              `destroy`, `rotate-webhook`, `register-webhook`)
     *
     * @return array<string, mixed>
     */
    protected function channelRow(Channel $channel, Closure $url): array
    {
        $record = ['record' => $channel->getKey()];

        return [
            'id'                 => (string) $channel->getKey(),
            'type'               => $channel->type->value,
            'typeLabel'          => trans($channel->type->labelKey()),
            'handle'             => $channel->publicHandle(),
            'url'                => $channel->publicUrl(),
            'isActive'           => $channel->is_active,
            'webhook'            => $this->webhookState($channel),
            'updatedAt'          => $channel->updated_at?->toIso8601String(),
            'editUrl'            => $url('edit', $record),
            'deleteUrl'          => $url('destroy', $record),
            'rotateUrl'          => $url('rotate-webhook', $record),
            'registerWebhookUrl' => $url('register-webhook', $record),
        ];
    }

    /**
     * The channel as the edit form receives it: the webhook hash and the settings of its type, never its secrets.
     *
     * @return array<string, mixed>
     */
    protected function editableChannel(Channel $channel, string $registerWebhookUrl): array
    {
        $isTelegram = ChannelTypeEnum::Telegram === $channel->type;
        $config     = is_array($channel->config) ? $channel->config : [];

        return [
            'id'                 => (string) $channel->getKey(),
            'type'               => $channel->type->value,
            'typeLabel'          => trans($channel->type->labelKey()),
            'isActive'           => $channel->is_active,
            'webhook'            => $this->webhookState($channel),
            'webhookAt'          => $channel->webhook_status_at?->toIso8601String(),
            'registerWebhookUrl' => $registerWebhookUrl,
            'webhookHash'        => $channel->webhook_public_hash,
            'handle'             => $channel->publicHandle(),
            'url'                => $channel->publicUrl(),
            'telegram'           => $isTelegram ? [
                'allowedUpdates' => $this->allowedUpdates($config),
                'maxConnections' => is_numeric($config['max_connections'] ?? null)
                    ? (int) $config['max_connections']
                    : TelegramWebhookOptions::MAX_CONNECTIONS_DEFAULT,
            ] : null,
            'configEntries' => $isTelegram ? null : $this->entries($config),
        ];
    }

    /**
     * What the form offers: the channel types and the Telegram update types, labelled in the interface language
     * from the labels the staff screens use, and the bounds of the delivery parallelism.
     *
     * @return array{types: list<array{value: string, label: string}>, telegramUpdates: list<array{value: string, label: string}>, maxConnections: array{min: int, max: int, default: int}}
     */
    protected function channelFormOptions(): array
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
     * What the screen says about the webhook: null when nothing is to be said (unknown, registered, or the channel is
     * off and has no webhook to register), `failed` when the provider refused the last registration.
     *
     * @return 'failed'|null
     */
    private function webhookState(Channel $channel): ?string
    {
        return $channel->webhookRegistrationFailed() ? 'failed' : null;
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
}
