<?php

declare(strict_types=1);

namespace App\Domains\Channels\Services;

use Fapost\Foundation\Channel\Settings\ChannelSettingField;
use Fapost\Foundation\Channel\Settings\ChannelSettingType;
use Illuminate\Validation\Rule;

/**
 * The validation rules of one setting of a channel's schema, built from its type, `min`, `max`, `required` and
 * `choices`. The channel form and the writer of managed values share them, so a value is held to the same rules
 * whoever sends it.
 */
final class ChannelSettingRules
{
    public const int MAX_TEXT = 65535;

    /**
     * @param  bool  $withRequired  false leaves `required` out: a value that is given is checked, one that is
     *                              missing is not asked for (the integration reports what it knows)
     *
     * @return array<string, list<mixed>> the rules by `config.<key>` (and `config.<key>.*` for a list)
     */
    public static function for(ChannelSettingField $field, bool $withRequired = true): array
    {
        $key      = "config.{$field->key}";
        $required = $withRequired && $field->required;
        $presence = $required ? ['required'] : ['nullable'];
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
                $key       => ['present', 'array', ...($required ? ['min:1'] : [])],
                "{$key}.*" => ['string', 'distinct', Rule::in($accepted)],
            ],
        };
    }

    /**
     * A validated value in the field's native type, as `channels.config` stores it.
     */
    public static function cast(ChannelSettingField $field, mixed $value): mixed
    {
        return match ($field->type) {
            ChannelSettingType::Integer => (int) $value,
            ChannelSettingType::Boolean => (bool) $value,
            ChannelSettingType::Choices => array_values((array) $value),
            default                     => (string) $value,
        };
    }
}
