<?php

declare(strict_types=1);

namespace App\Domains\Channels\Services;

use Fapost\Foundation\Channel\Settings\ChannelSettingField;

/**
 * A channel setting as a person reads it, in the interface language.
 *
 * An integration names its texts in English and may add a translation prefix ({@see ChannelSettingField::$translationKey}):
 * `{prefix}.label`, `{prefix}.help` and `{prefix}.choices.{value}` are looked up in Core's translations (a connector
 * adds its own lines with `loadTranslationsFrom`), and the English text of the declaration stands in for any that is
 * missing. A help text may use `:min`, `:max` and `:default`, filled from the field.
 */
final class ChannelSettingPresenter
{
    public static function label(ChannelSettingField $field): string
    {
        return self::translated($field, 'label') ?? $field->label;
    }

    public static function help(ChannelSettingField $field): ?string
    {
        if (null === $field->help) {
            return null;
        }

        $replace = [
            'min'     => (string) ($field->min ?? ''),
            'max'     => (string) ($field->max ?? ''),
            'default' => is_scalar($field->default) ? (string) $field->default : '',
        ];
        $text = self::translated($field, 'help', $replace) ?? $field->help;

        return strtr($text, array_combine(
            array_map(static fn (string $name): string => ':' . $name, array_keys($replace)),
            array_values($replace),
        ));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function choices(ChannelSettingField $field): array
    {
        $choices = [];

        foreach ($field->choices as $value => $label) {
            $choices[] = ['value' => (string) $value, 'label' => self::translated($field, "choices.{$value}") ?? $label];
        }

        return $choices;
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function translated(ChannelSettingField $field, string $part, array $replace = []): ?string
    {
        if (null === $field->translationKey) {
            return null;
        }

        $key  = "{$field->translationKey}.{$part}";
        $text = trans($key, $replace);

        return is_string($text) && $text !== $key ? $text : null;
    }
}
