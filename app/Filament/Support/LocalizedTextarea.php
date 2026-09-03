<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domains\Tenancy\Settings\TenantSettings;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;

/**
 * Filament form helper that emits a Tabs container with one Textarea per
 * platform locale. Component state lives under the given `statePath` as a
 * `lang => text` map matching the JSONB column shape produced by the
 * `localize_assistant_messages` migration.
 *
 * Used wherever an assistant-authored message needs locale variants
 * (assistant.fallback_message, assistant.busy_message, command text/response).
 * Tabs were chosen over a stacked Fieldset to keep vertical space tight —
 * editors usually fill one locale at a time.
 */
final class LocalizedTextarea
{
    /**
     * Build a Tabs component with one tab per available locale, each
     * containing a single Textarea bound to `{statePath}.{lang}`.
     *
     * @param  string|null  $tabsKey  optional persistence key; required only
     *                                when multiple Tabs siblings live in the
     *                                same form so Filament can disambiguate
     *                                their state.
     */
    public static function tabs(
        string $statePath,
        string $label,
        ?string $helperText = null,
        int $rows = 3,
        ?string $tabsKey = null,
    ): Component {
        $locales = self::locales();
        $tabs    = [];

        foreach ($locales as $lang) {
            $tabs[] = Tab::make(mb_strtoupper($lang))
                ->schema([
                    Textarea::make("{$statePath}.{$lang}")
                        ->label($label)
                        ->helperText($helperText)
                        ->nullable()
                        ->rows($rows)
                        ->columnSpanFull(),
                ]);
        }

        // Provide a stable key so Tabs nested inside a Repeater item don't
        // collide between iterations. Default to a path-derived slug.
        $component = Tabs::make()->tabs($tabs);

        if (null !== $tabsKey) {
            $component = $component->key($tabsKey);
        } else {
            $component = $component->key('localized_' . str_replace('.', '_', $statePath));
        }

        return $component;
    }

    /**
     * @return list<string>
     */
    private static function locales(): array
    {
        $configured = app(TenantSettings::class)->available_languages;

        if (! in_array('en', $configured, true)) {
            $configured = array_merge(['en'], $configured);
        }

        /** @var list<string> $list */
        $list = array_values(array_unique($configured));

        return $list;
    }
}
