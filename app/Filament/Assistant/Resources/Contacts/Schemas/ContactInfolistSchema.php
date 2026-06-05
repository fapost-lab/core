<?php

declare(strict_types=1);

namespace App\Filament\Assistant\Resources\Contacts\Schemas;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\VariableSchemaRegistryInterface;
use App\Domains\Flow\State\Variables\VariableType;
use Carbon\Carbon;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Throwable;

/**
 * Read-only contact card for the assistant panel.
 *
 * Renders {@code attributes} JSON as group-aware sections per task 08:
 *  - Header: identity columns (channel, external_id, language, optional username).
 *  - Profile: scalar root-level keys.
 *  - One Section per attribute group (nested object) — collapsible, count
 *    pluralised via {@code contact.sections.group_fields}.
 *  - From platform: collapsed Section exposing the {@code meta} JSON.
 *
 * Group/Profile sections start expanded when the contact has ≤ 3 groups, else
 * collapsed by default — keeps the page scannable for survey-heavy contacts.
 *
 * Field values are formatted according to the declared VariableType from the
 * tenant schema registry when available; raw string fallback for legacy fields.
 */
final class ContactInfolistSchema
{
    /**
     * Threshold below which group sections render expanded by default.
     */
    private const GROUP_EXPAND_THRESHOLD = 3;

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            self::headerSection(),
            self::profileSection(),
            ...self::groupSectionsResolver(),
            self::fromPlatformSection(),
        ]);
    }

    /**
     * Identity row: channel, external_id, language, optional username.
     */
    private static function headerSection(): Component
    {
        return Section::make(__('contact.sections.header'))
            ->columns(3)
            ->components([
                TextEntry::make('platform')
                    ->label(__('contact.fields.platform'))
                    ->badge()
                    ->formatStateUsing(static fn (PlatformEnum $state): string => ucfirst($state->value)),
                TextEntry::make('external_id')
                    ->label(__('contact.fields.external_id'))
                    ->copyable()
                    ->fontFamily('mono')
                    ->placeholder(self::placeholder()),
                TextEntry::make('language')
                    ->label(__('contact.fields.language'))
                    ->badge()
                    ->placeholder(self::placeholder()),
                TextEntry::make('meta_username')
                    ->label(__('contact.fields.username'))
                    ->state(static fn (Contact $record): ?string => self::metaString($record, 'username'))
                    ->placeholder(self::placeholder()),
            ]);
    }

    /**
     * Profile section — root-level scalar attributes (skip nested groups).
     */
    private static function profileSection(): Component
    {
        return Section::make(__('contact.sections.profile'))
            ->collapsible()
            ->collapsed(static fn (Contact $record): bool => self::shouldCollapseGroups($record))
            ->components(static fn (?Contact $record): array => null === $record
                ? []
                : self::rootAttributeFields($record))
            ->visible(static fn (?Contact $record): bool => null !== $record && [] !== self::rootAttributeKeys($record));
    }

    /**
     * Lazy resolver for group sections: Filament evaluates the schema against
     * the resolved record, so the closure runs once per page render with the
     * concrete contact already loaded.
     *
     * @return list<Component>
     */
    private static function groupSectionsResolver(): array
    {
        // The schema array is built at form-construction time; nested
        // sections need a per-record component list, so we wrap them in a
        // single dynamic Section that delegates to the record.
        return [
            Section::make('')
                ->hiddenLabel()
                ->compact()
                ->components(static fn (?Contact $record): array => null === $record
                    ? []
                    : self::groupSections($record))
                ->visible(static fn (?Contact $record): bool => null !== $record && [] !== self::groupKeys($record)),
        ];
    }

    private static function fromPlatformSection(): Component
    {
        return Section::make(__('contact.sections.from_platform'))
            ->collapsible()
            ->collapsed()
            ->components(static fn (?Contact $record): array => null === $record
                ? []
                : self::metaFields($record))
            ->visible(static fn (?Contact $record): bool => null !== $record && [] !== self::metaArray($record));
    }

    /**
     * Build {@see TextEntry} list for top-level scalar keys of `attributes`.
     *
     * @return list<TextEntry>
     */
    private static function rootAttributeFields(Contact $contact): array
    {
        $registry = self::registry();
        $entries  = [];

        foreach (self::rootAttributeKeys($contact) as $key) {
            $type      = $registry?->get('contact', null, $key);
            $entries[] = TextEntry::make("attributes.{$key}")
                ->label($key)
                ->state(static fn (Contact $record): string => self::formatValue(
                    $record->attributes[$key] ?? null,
                    $type,
                ))
                ->placeholder(self::placeholder());
        }

        return $entries;
    }

    /**
     * Build a collapsible Section per attribute group (nested object).
     *
     * @return list<Component>
     */
    private static function groupSections(Contact $contact): array
    {
        $sections  = [];
        $collapsed = self::shouldCollapseGroups($contact);

        foreach (self::groupKeys($contact) as $group) {
            $groupData = self::groupData($contact, $group);
            $count     = count($groupData);

            $sections[] = Section::make(self::groupHeading($group, $count))
                ->collapsible()
                ->collapsed($collapsed)
                ->components(self::groupFields($group, $groupData));
        }

        return $sections;
    }

    /**
     * @return list<TextEntry>
     */
    private static function metaFields(Contact $contact): array
    {
        $entries = [];

        foreach (self::metaArray($contact) as $key => $value) {
            $entries[] = TextEntry::make("meta.{$key}")
                ->label((string) $key)
                ->state(static fn () => self::stringify($value))
                ->placeholder(self::placeholder());
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $groupData
     * @return list<TextEntry>
     */
    private static function groupFields(string $group, array $groupData): array
    {
        $registry = self::registry();
        $entries  = [];

        foreach ($groupData as $key => $value) {
            $type      = $registry?->get('contact', $group, (string)$key);
            $entries[] = TextEntry::make("attributes.{$group}.{$key}")
                ->label((string) $key)
                ->state(static fn () => self::formatValue($value, $type))
                ->placeholder(self::placeholder());
        }

        return $entries;
    }

    private static function registry(): ?VariableSchemaRegistryInterface
    {
        try {
            return app(VariableSchemaRegistryInterface::class);
        } catch (Throwable) {
            return null;
        }
    }

    private static function groupHeading(string $group, int $count): string
    {
        $suffix = trans_choice('contact.sections.group_fields', $count, ['count' => $count]);

        return "{$group} ({$suffix})";
    }

    /**
     * @return list<string>
     */
    private static function rootAttributeKeys(Contact $contact): array
    {
        $keys = [];

        foreach (self::attributesArray($contact) as $key => $value) {
            if (!is_array($value)) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private static function groupKeys(Contact $contact): array
    {
        $keys = [];

        foreach (self::attributesArray($contact) as $key => $value) {
            if (is_array($value) && [] !== $value) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private static function groupData(Contact $contact, string $group): array
    {
        $value = self::attributesArray($contact)[$group] ?? [];

        return is_array($value) ? $value : [];
    }

    private static function shouldCollapseGroups(Contact $contact): bool
    {
        return count(self::groupKeys($contact)) > self::GROUP_EXPAND_THRESHOLD;
    }

    /**
     * @return array<string, mixed>
     */
    private static function attributesArray(Contact $contact): array
    {
        return is_array($contact->attributes) ? $contact->attributes : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function metaArray(Contact $contact): array
    {
        return is_array($contact->meta) ? $contact->meta : [];
    }

    private static function metaString(Contact $contact, string $key): ?string
    {
        $value = self::metaArray($contact)[$key] ?? null;

        return null === $value ? null : self::stringify($value);
    }

    private static function formatValue(mixed $value, ?VariableType $type): string
    {
        if (null === $value || '' === $value) {
            return '';
        }

        if (null === $type) {
            return self::stringify($value);
        }

        return match ($type) {
            VariableType::Number => is_numeric($value)
                ? number_format((float)$value, str_contains((string)$value, '.') ? 2 : 0, '.', ',')
                : self::stringify($value),

            VariableType::Date => self::formatDate($value),

            VariableType::Json => is_scalar($value) || null === $value
                ? self::stringify($value)
                : (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),

            VariableType::Confirm => match (true) {
                true === $value
                || in_array(
                    mb_strtolower((string)$value),
                    ['true', '1', 'yes', 'y', 'on', 'да'],
                    true
                ) => __('contact.values.yes'),
                false === $value
                || in_array(
                    mb_strtolower((string)$value),
                    ['false', '0', 'no', 'n', 'off', 'нет'],
                    true
                )       => __('contact.values.no'),
                default => self::stringify($value),
            },

            default => self::stringify($value),
        };
    }

    private static function formatDate(mixed $value): string
    {
        try {
            return Carbon::parse((string)$value)->translatedFormat('d M Y');
        } catch (Throwable) {
            return self::stringify($value);
        }
    }

    private static function stringify(mixed $value): string
    {
        if (null === $value) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function placeholder(): string
    {
        return __('contact.placeholders.empty');
    }
}
