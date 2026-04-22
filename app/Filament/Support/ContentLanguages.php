<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * Practical set of content languages for assistant bots.
 *
 * Covers major global languages without obscure or rarely used locales.
 */
final class ContentLanguages
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'ar' => 'Arabic — العربية',
            'az' => 'Azerbaijani — Azərbaycan',
            'be' => 'Belarusian — Беларуская',
            'bg' => 'Bulgarian — Български',
            'ca' => 'Catalan — Català',
            'cs' => 'Czech — Čeština',
            'da' => 'Danish — Dansk',
            'de' => 'German — Deutsch',
            'el' => 'Greek — Ελληνικά',
            'en' => 'English',
            'es' => 'Spanish — Español',
            'et' => 'Estonian — Eesti',
            'fa' => 'Persian — فارسی',
            'fi' => 'Finnish — Suomi',
            'fr' => 'French — Français',
            'he' => 'Hebrew — עברית',
            'hi' => 'Hindi — हिन्दी',
            'hr' => 'Croatian — Hrvatski',
            'hu' => 'Hungarian — Magyar',
            'hy' => 'Armenian — Հայերեն',
            'id' => 'Indonesian — Indonesia',
            'it' => 'Italian — Italiano',
            'ja' => 'Japanese — 日本語',
            'ka' => 'Georgian — ქართული',
            'kk' => 'Kazakh — Қазақша',
            'ko' => 'Korean — 한국어',
            'lt' => 'Lithuanian — Lietuvių',
            'lv' => 'Latvian — Latviešu',
            'mk' => 'Macedonian — Македонски',
            'ms' => 'Malay — Melayu',
            'nl' => 'Dutch — Nederlands',
            'no' => 'Norwegian — Norsk',
            'pl' => 'Polish — Polski',
            'pt' => 'Portuguese — Português',
            'ro' => 'Romanian — Română',
            'ru' => 'Russian — Русский',
            'sk' => 'Slovak — Slovenčina',
            'sl' => 'Slovenian — Slovenščina',
            'sq' => 'Albanian — Shqip',
            'sr' => 'Serbian — Српски',
            'sv' => 'Swedish — Svenska',
            'th' => 'Thai — ภาษาไทย',
            'tr' => 'Turkish — Türkçe',
            'uk' => 'Ukrainian — Українська',
            'uz' => 'Uzbek — Oʻzbekcha',
            'vi' => 'Vietnamese — Tiếng Việt',
            'zh' => 'Chinese — 中文',
        ];
    }
}
