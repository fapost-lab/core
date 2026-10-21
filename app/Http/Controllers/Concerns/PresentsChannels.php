<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\ChannelPublicLinks;
use App\Domains\Channels\Services\ChannelSettingPresenter;
use Closure;
use Fapost\Foundation\Channel\Settings\ChannelSettingField;
use Fapost\Foundation\Channel\Settings\ChannelSettingType;

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
        $link   = app(ChannelPublicLinks::class)->for($channel);

        return [
            'id'                 => (string) $channel->getKey(),
            'type'               => $channel->type,
            'typeLabel'          => $this->typeLabel($channel),
            'available'          => $this->isAvailable($channel),
            'handle'             => $link?->handle,
            'url'                => $link?->url,
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
        $link = app(ChannelPublicLinks::class)->for($channel);

        return [
            'id'                 => (string) $channel->getKey(),
            'type'               => $channel->type,
            'typeLabel'          => $this->typeLabel($channel),
            'available'          => $this->isAvailable($channel),
            'isActive'           => $channel->is_active,
            'webhook'            => $this->webhookState($channel),
            'webhookAt'          => $channel->webhook_status_at?->toIso8601String(),
            'registerWebhookUrl' => $registerWebhookUrl,
            'webhookHash'        => $channel->webhook_public_hash,
            'handle'             => $link?->handle,
            'url'                => $link?->url,
            'settings'           => $this->settingValues($channel),
        ];
    }

    /**
     * What the form offers: the channel types, and for each the settings its integration declared (labelled in the
     * interface language) and whether it uses a secret token.
     *
     * @return array{types: list<array{value: string, label: string, usesSecretToken: bool}>, settings: array<string, list<array<string, mixed>>>}
     */
    protected function channelFormOptions(): array
    {
        $registry = app(ChannelRegistryInterface::class);
        $types    = [];
        $settings = [];

        foreach ($registry->types() as $type) {
            $schema  = $registry->profile($type)->settings();
            $types[] = [
                'value'           => $type,
                'label'           => $registry->label($type),
                'usesSecretToken' => $schema->usesSecretToken,
            ];
            $settings[$type] = array_map($this->settingField(...), $schema->editable());
        }

        return ['types' => $types, 'settings' => $settings];
    }

    /**
     * Refuses to open or change the settings of a channel whose type nothing serves.
     */
    protected function abortIfUnavailable(Channel $channel): void
    {
        abort_unless($this->isAvailable($channel), 409, 'The channel type is not available.');
    }

    /**
     * Whether an integration still serves the channel's type. A channel of a removed connector is listed, marked, and
     * only deleted: its settings belong to an integration that is not there to read them.
     */
    private function isAvailable(Channel $channel): bool
    {
        return app(ChannelRegistryInterface::class)->has($channel->type);
    }

    private function typeLabel(Channel $channel): string
    {
        return app(ChannelRegistryInterface::class)->label($channel->type);
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
     * A declared setting as the form renders it: texts already translated.
     *
     * @return array<string, mixed>
     */
    private function settingField(ChannelSettingField $field): array
    {
        return [
            'key'      => $field->key,
            'type'     => $field->type->value,
            'label'    => ChannelSettingPresenter::label($field),
            'help'     => ChannelSettingPresenter::help($field),
            'required' => $field->required,
            'default'  => $field->default,
            'min'      => $field->min,
            'max'      => $field->max,
            'choices'  => ChannelSettingPresenter::choices($field),
        ];
    }

    /**
     * The stored value of every editable setting of the channel's type, typed; a missing or unfit one is the default.
     * Only values the form can offer are given: a stored choice outside the declared ones would fail validation with
     * nothing to correct.
     *
     * @return array<string, bool|int|string|list<string>|null>
     */
    private function settingValues(Channel $channel): array
    {
        $config = is_array($channel->config) ? $channel->config : [];
        $values = [];

        foreach (app(ChannelRegistryInterface::class)->profile($channel->type)->settings()->editable() as $field) {
            $values[$field->key] = $this->settingValue($field, $config[$field->key] ?? null);
        }

        return $values;
    }

    private function settingValue(ChannelSettingField $field, mixed $stored): bool|int|string|array|null
    {
        $accepted = array_map(strval(...), array_keys($field->choices));

        $value = match ($field->type) {
            ChannelSettingType::Text    => is_string($stored) ? $stored : (is_numeric($stored) ? (string) $stored : null),
            ChannelSettingType::Integer => is_numeric($stored) ? (int) $stored : null,
            ChannelSettingType::Boolean => is_bool($stored) ? $stored : null,
            ChannelSettingType::Choice  => is_string($stored) && in_array($stored, $accepted, true) ? $stored : null,
            ChannelSettingType::Choices => is_array($stored)
                ? array_values(array_filter($stored, static fn (mixed $item): bool => is_string($item) && in_array($item, $accepted, true)))
                : null,
        };

        return $value ?? $field->default;
    }
}
