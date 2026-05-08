<?php

declare(strict_types=1);

namespace App\Domains\Flow\Translations;

use App\Domains\Flow\Contracts\SystemTranslationCatalogInterface;

/**
 * Built-in catalog seeds owned by Core. Features and Solutions register
 * their own entries through the same catalog.
 *
 * Default texts are authored on en/ru/uk — the three locales we currently
 * ship as platform languages. Extending tenants to other locales happens
 * exclusively through tenant_translations overrides.
 */
final class CoreSystemTranslations
{
    public static function seed(SystemTranslationCatalogInterface $catalog): void
    {
        foreach (self::entries() as $entry) {
            $catalog->register($entry);
        }
    }

    /**
     * @return list<SystemTranslationEntry>
     */
    private static function entries(): array
    {
        return [
            // ── Built-in commands ────────────────────────────────────────────
            new SystemTranslationEntry(
                key: 'commands.reset.response',
                group: 'commands',
                description: 'Sent after the built-in /reset command terminates the active session.',
                defaults: [
                    'en' => 'Conversation reset.',
                    'ru' => 'Диалог сброшен.',
                    'uk' => 'Діалог скинуто.',
                ],
            ),
            new SystemTranslationEntry(
                key: 'commands.cancel.response',
                group: 'commands',
                description: 'Sent after the built-in /cancel command terminates the active session.',
                defaults: [
                    'en' => 'Action cancelled.',
                    'ru' => 'Действие отменено.',
                    'uk' => 'Дію скасовано.',
                ],
            ),

            // ── System errors / fallbacks ────────────────────────────────────
            new SystemTranslationEntry(
                key: 'errors.session_expired',
                group: 'errors',
                description: 'Shown when a contact resumes a conversation whose session has already expired.',
                defaults: [
                    'en' => 'Your session has expired. Please start over.',
                    'ru' => 'Сессия истекла. Пожалуйста, начните заново.',
                    'uk' => 'Сесія закінчилася. Будь ласка, почніть заново.',
                ],
            ),
            new SystemTranslationEntry(
                key: 'errors.fallback',
                group: 'errors',
                description: 'Generic fallback when the bot cannot match incoming text to any active flow or command.',
                defaults: [
                    'en' => 'Sorry, I did not understand that.',
                    'ru' => 'Извините, я не понял.',
                    'uk' => 'Вибачте, я не зрозумів.',
                ],
            ),
            new SystemTranslationEntry(
                key: 'errors.flow_not_found',
                group: 'errors',
                description: 'Sent when a command tries to start a flow that no longer exists or is inactive.',
                defaults: [
                    'en' => 'This action is not available right now.',
                    'ru' => 'Это действие сейчас недоступно.',
                    'uk' => 'Ця дія зараз недоступна.',
                ],
            ),
            new SystemTranslationEntry(
                key: 'errors.busy',
                group: 'errors',
                description: 'Sent when an inbound message is dropped because the session is locked or paused. Overridden by assistant.busy_message when configured.',
                defaults: [
                    'en' => 'The bot is busy, please try again in a few seconds.',
                    'ru' => 'Бот занят, попробуйте через несколько секунд.',
                    'uk' => 'Бот зайнятий, спробуйте за кілька секунд.',
                ],
            ),

            // ── Default service buttons ──────────────────────────────────────
            new SystemTranslationEntry(
                key: 'buttons.yes',
                group: 'buttons',
                description: 'Default label for affirmative confirmation buttons.',
                defaults: ['en' => 'Yes', 'ru' => 'Да', 'uk' => 'Так'],
            ),
            new SystemTranslationEntry(
                key: 'buttons.no',
                group: 'buttons',
                description: 'Default label for negative confirmation buttons.',
                defaults: ['en' => 'No', 'ru' => 'Нет', 'uk' => 'Ні'],
            ),
            new SystemTranslationEntry(
                key: 'buttons.confirm',
                group: 'buttons',
                description: 'Default label for explicit confirmation buttons.',
                defaults: ['en' => 'Confirm', 'ru' => 'Подтвердить', 'uk' => 'Підтвердити'],
            ),
            new SystemTranslationEntry(
                key: 'buttons.cancel',
                group: 'buttons',
                description: 'Default label for cancel buttons.',
                defaults: ['en' => 'Cancel', 'ru' => 'Отмена', 'uk' => 'Скасувати'],
            ),
            new SystemTranslationEntry(
                key: 'buttons.back',
                group: 'buttons',
                description: 'Default label for back navigation buttons.',
                defaults: ['en' => 'Back', 'ru' => 'Назад', 'uk' => 'Назад'],
            ),
        ];
    }
}
