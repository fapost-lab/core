<?php

declare(strict_types=1);

namespace App\Domains\Contact\Services;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\VariableSchemaRegistryInterface;
use App\Domains\Flow\State\Variables\VariableType;
use Carbon\Carbon;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * The read-only card of a {@see Contact}: its `attributes` JSON laid out as a profile, one section per attribute group
 * (a nested object) and the platform's own `meta`, with every value already formatted as text.
 *
 * Properties are lists of `{key, value}`, not maps: a PHP map with keys such as "1" and "10" becomes a JS object whose
 * numeric keys are reordered, and Postgres `jsonb` reorders keys on its own. Keys are sorted (natural, case-insensitive)
 * so the order is the same on every engine. Values are formatted by the type the tenant's variable schema declares, in
 * the locale of the request; a variable with no declared type is shown as it is stored.
 *
 * The same layout as the Filament infolist, which stays as it was while the old console is still served.
 */
final readonly class ContactCard
{
    /**
     * With more groups than this the profile and the group sections start collapsed.
     */
    public const int GROUP_EXPAND_THRESHOLD = 3;

    public function __construct(private Container $container)
    {
    }

    /**
     * @return array{
     *     profile: list<array{key: string, value: string}>,
     *     groups: list<array{key: string, fieldsLabel: string, fields: list<array{key: string, value: string}>}>,
     *     meta: list<array{key: string, value: string}>,
     *     collapsed: bool,
     * }
     */
    public function sections(Contact $contact): array
    {
        $attributes = $this->arrayOf($contact->getAttribute('attributes'));
        $registry   = $this->registry();
        $profile    = [];
        $groups     = [];

        foreach ($attributes as $key => $value) {
            $key = (string) $key;

            if (is_array($value) && [] !== $value && ! array_is_list($value)) {
                $groups[$key] = $value;

                continue;
            }

            $profile[$key] = $this->rootValue($value, $this->typeOf($registry, null, $key));
        }

        $sections = [];

        foreach ($this->sortedKeys($groups) as $group) {
            $fields = [];

            foreach ($this->sortedKeys($groups[$group]) as $key) {
                $fields[$key] = $this->formatValue($groups[$group][$key], $this->typeOf($registry, $group, $key));
            }

            $count = count($fields);

            $sections[] = [
                'key'         => $group,
                'fieldsLabel' => trans_choice('console.contacts.view.group_fields', $count, ['count' => $count]),
                'fields'      => $this->asList($fields),
            ];
        }

        $meta = [];

        foreach ($this->arrayOf($contact->getAttribute('meta')) as $key => $value) {
            $meta[(string) $key] = $this->stringify($value);
        }

        return [
            'profile'   => $this->asList($profile),
            'groups'    => $sections,
            'meta'      => $this->asList($meta),
            'collapsed' => count($sections) > self::GROUP_EXPAND_THRESHOLD,
        ];
    }

    /**
     * A value at the root of the attributes: a scalar, or a list shown as one field (an empty array is an empty field).
     */
    private function rootValue(mixed $value, ?VariableType $type): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(
                fn (mixed $item): string => $this->stringify($item),
                array_values($value),
            ));
        }

        return $this->formatValue($value, $type);
    }

    private function typeOf(?VariableSchemaRegistryInterface $registry, ?string $group, string $key): ?VariableType
    {
        try {
            return $registry?->get('contact', $group, $key);
        } catch (Throwable) {
            return null;
        }
    }

    private function registry(): ?VariableSchemaRegistryInterface
    {
        try {
            return $this->container->make(VariableSchemaRegistryInterface::class);
        } catch (Throwable) {
            return null;
        }
    }

    private function formatValue(mixed $value, ?VariableType $type): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        if (null === $type) {
            return $this->stringify($value);
        }

        return match ($type) {
            VariableType::Number => is_numeric($value)
                ? number_format((float) $value, str_contains((string) $value, '.') ? 2 : 0, '.', ',')
                : $this->stringify($value),

            VariableType::Date => $this->formatDate($value),

            VariableType::Json => is_scalar($value)
                ? $this->stringify($value)
                : (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),

            VariableType::Confirm => $this->formatConfirm($value),

            default => $this->stringify($value),
        };
    }

    private function formatConfirm(mixed $value): string
    {
        if (is_array($value)) {
            return $this->stringify($value);
        }

        $text = mb_strtolower((string) $value);

        return match (true) {
            true === $value || in_array($text, ['true', '1', 'yes', 'y', 'on', 'да'], true)    => trans('contact.values.yes'),
            false === $value || in_array($text, ['false', '0', 'no', 'n', 'off', 'нет'], true) => trans('contact.values.no'),
            default                                                                            => $this->stringify($value),
        };
    }

    private function formatDate(mixed $value): string
    {
        try {
            return Carbon::parse($this->stringify($value))->translatedFormat('d M Y');
        } catch (Throwable) {
            return $this->stringify($value);
        }
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            null === $value   => '',
            is_bool($value)   => $value ? 'true' : 'false',
            is_string($value) => $value,
            is_scalar($value) => (string) $value,
            default           => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arrayOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<array-key, mixed>  $map
     *
     * @return list<string>
     */
    private function sortedKeys(array $map): array
    {
        $keys = array_map(static fn (int|string $key): string => (string) $key, array_keys($map));

        usort($keys, static fn (string $a, string $b): int => strnatcasecmp($a, $b) ?: strcmp($a, $b));

        return $keys;
    }

    /**
     * @param  array<string, string>  $map
     *
     * @return list<array{key: string, value: string}>
     */
    private function asList(array $map): array
    {
        $list = [];

        foreach ($this->sortedKeys($map) as $key) {
            $list[] = ['key' => $key, 'value' => $map[$key]];
        }

        return $list;
    }
}
