<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Services\ChannelSettingPresenter;
use Fapost\Foundation\Channel\Settings\ChannelSettingField;
use Fapost\Foundation\Channel\Settings\ChannelSettingType;
use Illuminate\Validation\Rule;

/**
 * What the channel form sends, as validation rules and as the fields {@see \App\Domains\Channels\Services\AssistantChannelService}
 * takes. It holds no state, so any screen that edits a channel (the assistant console, the tenant-wide admin) shares it.
 *
 * The settings come from the profile the channel's integration declared ({@see \Fapost\Foundation\Channel\Settings\ChannelProfileInterface}):
 * each editable field of its schema becomes one rule set under `config.<key>`, whatever the provider. The type is
 * chosen when the channel is created and does not change afterwards, so a change has no `type` rule and no `type` field.
 */
final class ChannelFieldRules
{
    private const int MAX_TEXT = 65535;

    /**
     * @param  string|null  $type      the type of the channel: the one sent when creating (null: not a registered type,
     *                                 so only the type rule can fail), the stored one when changing
     * @param  bool         $creating  a token and a secret are required; changing, an empty one keeps what is stored
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(?string $type, bool $creating): array
    {
        $registry = app(ChannelRegistryInterface::class);
        $schema   = null === $type ? null : $registry->profile($type)->settings();
        $presence = $creating ? ['required'] : ['nullable'];
        if (null === $schema || $schema->usesSecretToken) {
            $secret = [...$presence, 'string', 'max:' . self::MAX_TEXT];

            if (null !== $schema?->secretTokenPattern) {
                $secret[] = 'regex:' . self::secretTokenRegex($schema->secretTokenPattern);
            }
        } else {
            $secret = ['nullable', 'string', 'max:' . self::MAX_TEXT];
        }

        $rules = [
            'token'        => [...$presence, 'string', 'max:' . self::MAX_TEXT],
            'secret_token' => $secret,
            'is_active'    => ['required', 'boolean'],
        ];

        if ($creating) {
            $rules['type'] = ['required', 'string', Rule::in($registry->types())];
        }

        if (null === $schema) {
            return $rules;
        }

        $rules['config'] = ['present', 'array'];

        foreach ($schema->editable() as $field) {
            $rules = [...$rules, ...self::fieldRules($field)];
        }

        return $rules;
    }

    /**
     * The names the errors speak of: the translated label of each setting, instead of `config.max connections`.
     *
     * @return array<string, string>
     */
    public static function attributes(?string $type): array
    {
        if (null === $type) {
            return [];
        }

        $attributes = [];

        foreach (app(ChannelRegistryInterface::class)->profile($type)->settings()->editable() as $field) {
            $attributes["config.{$field->key}"] = ChannelSettingPresenter::label($field);
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $validated
     *
     * @return array<string, mixed>
     */
    public static function fields(array $validated, string $type, bool $creating): array
    {
        $fields = [
            'is_active' => (bool) $validated['is_active'],
            'config'    => self::config($validated, $type),
        ];

        if ($creating) {
            $fields['type'] = $type;
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
    private static function fieldRules(ChannelSettingField $field): array
    {
        $key      = "config.{$field->key}";
        $presence = $field->required ? ['required'] : ['nullable'];
        $accepted = array_map(strval(...), array_keys($field->choices));

        return match ($field->type) {
            ChannelSettingType::Text => [
                $key => [...$presence, 'string', 'max:' . ($field->max ?? self::MAX_TEXT)],
            ],
            ChannelSettingType::Integer => [
                $key => [
                    ...$presence,
                    'integer',
                    ...(null === $field->min ? [] : ['min:' . $field->min]),
                    ...(null === $field->max ? [] : ['max:' . $field->max]),
                ],
            ],
            ChannelSettingType::Boolean => [
                $key => [...$presence, 'boolean'],
            ],
            ChannelSettingType::Choice => [
                $key => [...$presence, 'string', Rule::in($accepted)],
            ],
            ChannelSettingType::Choices => [
                $key       => ['present', 'array', ...($field->required ? ['min:1'] : [])],
                "{$key}.*" => ['string', 'distinct', Rule::in($accepted)],
            ],
        };
    }

    /**
     * Only the editable settings of the schema, typed; managed ones are the integration's and never come from a request.
     *
     * @param  array<string, mixed>  $validated
     *
     * @return array<string, mixed>
     */
    private static function config(array $validated, string $type): array
    {
        $submitted = is_array($validated['config'] ?? null) ? $validated['config'] : [];
        $config    = [];

        foreach (app(ChannelRegistryInterface::class)->profile($type)->settings()->editable() as $field) {
            $value = $submitted[$field->key] ?? null;

            if (null === $value) {
                continue;
            }

            $config[$field->key] = match ($field->type) {
                ChannelSettingType::Integer => (int) $value,
                ChannelSettingType::Boolean => (bool) $value,
                ChannelSettingType::Choices => array_values((array) $value),
                default                     => (string) $value,
            };
        }

        return $config;
    }

    /**
     * A schema's pattern is PCRE without delimiters; the whole secret has to match it.
     */
    private static function secretTokenRegex(string $pattern): string
    {
        return '/^(?:' . str_replace('/', '\/', $pattern) . ')$/';
    }
}
