<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Telegram\TelegramWebhookOptions;
use Illuminate\Validation\Rule;

/**
 * What the channel form sends, as validation rules and as the fields {@see \App\Domains\Channels\Services\AssistantChannelService}
 * takes. It holds no state, so any screen that edits a channel (the assistant console, the tenant-wide admin) shares it.
 *
 * The settings depend on the channel's type: Telegram has two named ones (`config.allowed_updates`,
 * `config.max_connections`), any other type a free list of key/value pairs (`config_entries`). The type is chosen when
 * the channel is created and does not change afterwards, so a change has no `type` rule and no `type` field.
 */
final class ChannelFieldRules
{
    /**
     * Telegram accepts a secret token of 1 to 256 characters from `A-Z a-z 0-9 _ -`.
     */
    private const string TELEGRAM_SECRET = '/^[A-Za-z0-9_-]{1,256}$/';

    private const int MAX_ENTRIES = 50;

    /**
     * @param  ChannelTypeEnum|null  $type      the type of the channel: the one sent when creating (null: not a known type,
     *                                          so only the type rule can fail), the stored one when changing
     * @param  bool                  $creating  a token and a secret are required; changing, an empty one keeps what is stored
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(?ChannelTypeEnum $type, bool $creating): array
    {
        $secret = ['string', 'max:65535'];

        if (ChannelTypeEnum::Telegram === $type) {
            $secret[] = 'regex:' . self::TELEGRAM_SECRET;
        }

        $presence = $creating ? ['required'] : ['nullable'];
        $rules    = [
            'token'        => [...$presence, 'string', 'max:65535'],
            'secret_token' => [...$presence, ...$secret],
            'is_active'    => ['required', 'boolean'],
        ];

        if ($creating) {
            $rules['type'] = ['required', 'string', Rule::enum(ChannelTypeEnum::class)];
        }

        return match ($type) {
            ChannelTypeEnum::Telegram => $rules + self::telegramRules(),
            null                      => $rules,
            default                   => $rules + self::entryRules(),
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @return array<string, mixed>
     */
    public static function fields(array $validated, ChannelTypeEnum $type, bool $creating): array
    {
        $fields = [
            'is_active' => (bool) $validated['is_active'],
            'config'    => self::config($validated, $type),
        ];

        if ($creating) {
            $fields['type'] = $type->value;
        }

        foreach (['token', 'secret_token'] as $secret) {
            $value = $validated[$secret] ?? null;

            // Changing a channel, an empty secret means "keep it"; creating one, it is required, so never empty.
            if (is_string($value) && '' !== $value) {
                $fields[$secret] = $value;
            }
        }

        return $fields;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function telegramRules(): array
    {
        return [
            'config'                   => ['required', 'array'],
            'config.allowed_updates'   => ['present', 'array'],
            'config.allowed_updates.*' => ['string', 'distinct', Rule::in(TelegramWebhookOptions::ALLOWED_UPDATES)],
            'config.max_connections'   => [
                'required',
                'integer',
                'between:' . TelegramWebhookOptions::MAX_CONNECTIONS_MIN . ',' . TelegramWebhookOptions::MAX_CONNECTIONS_MAX,
            ],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function entryRules(): array
    {
        return [
            'config_entries'         => ['present', 'array', 'max:' . self::MAX_ENTRIES],
            'config_entries.*.key'   => ['required', 'string', 'max:255', 'distinct'],
            'config_entries.*.value' => ['nullable', 'string', 'max:65535'],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @return array<string, mixed>
     */
    private static function config(array $validated, ChannelTypeEnum $type): array
    {
        if (ChannelTypeEnum::Telegram === $type) {
            /** @var array{allowed_updates: list<string>, max_connections: int|string} $config */
            $config = $validated['config'];

            return [
                'allowed_updates' => array_values($config['allowed_updates']),
                'max_connections' => (int) $config['max_connections'],
            ];
        }

        /** @var list<array{key: string, value?: string|null}> $entries */
        $entries = $validated['config_entries'] ?? [];
        $config  = [];

        foreach ($entries as $entry) {
            $config[$entry['key']] = $entry['value'] ?? '';
        }

        return $config;
    }
}
