<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowTrigger;
use Illuminate\Support\Str;

/**
 * The one-line summary of a flow's trigger shown under its name in a list: what starts the flow, as plain text and
 * the trigger type (the screen picks the icon).
 *
 * The same logic as the Filament flows table has, without its HTML; that copy goes away with Filament.
 */
final class FlowTriggerHint
{
    /** How many keywords and phrases the summary names before it counts the rest. */
    private const int MESSAGE_ITEMS = 6;

    /** The longest message summary, in characters, before it is cut. */
    private const int MESSAGE_LENGTH = 80;

    /**
     * @return array{type: string, text: string}|null null when the flow has no trigger or its trigger says nothing
     */
    public static function for(?FlowTrigger $trigger): ?array
    {
        if (null === $trigger) {
            return null;
        }

        $config = is_array($trigger->config) ? $trigger->config : [];

        $text = match ($trigger->type) {
            FlowTriggerType::Message  => self::messageText($config),
            FlowTriggerType::Schedule => self::nonEmpty($config['cron'] ?? null),
            FlowTriggerType::Webhook  => self::webhookText($config),
            FlowTriggerType::Event    => self::nonEmpty($config['event_name'] ?? null),
            FlowTriggerType::Api      => self::nonEmpty($config['route_key'] ?? null),
        };

        return null === $text ? null : ['type' => $trigger->type->value, 'text' => $text];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function messageText(array $config): ?string
    {
        $all = array_merge(self::items($config['keywords'] ?? []), self::items($config['phrases'] ?? []));

        if ([] === $all) {
            return null;
        }

        $text = implode(', ', array_slice($all, 0, self::MESSAGE_ITEMS));

        if (count($all) > self::MESSAGE_ITEMS) {
            $text .= ' +' . (count($all) - self::MESSAGE_ITEMS);
        }

        return Str::limit($text, self::MESSAGE_LENGTH, '…');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function webhookText(array $config): ?string
    {
        $method = self::nonEmpty($config['method'] ?? null);
        $path   = self::nonEmpty($config['path'] ?? null);

        if (null === $method && null === $path) {
            return null;
        }

        return mb_trim(($method ?? '') . ' ' . ($path ?? ''));
    }

    /**
     * @return list<string>
     */
    private static function items(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): ?string => self::nonEmpty($value), $values),
            static fn (?string $value): bool => null !== $value,
        ));
    }

    private static function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && '' !== mb_trim($value) ? mb_trim($value) : null;
    }
}
