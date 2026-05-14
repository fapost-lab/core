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
                description: [
                    'en' => 'Sent after the built-in /reset command terminates the active session.',
                    'ru' => 'Отправляется после того, как встроенная команда /reset завершает активную сессию.',
                    'uk' => 'Надсилається після того, як вбудована команда /reset завершує активну сесію.',
                ],
                defaults: [
                    'en' => 'Conversation reset.',
                    'ru' => 'Диалог сброшен.',
                    'uk' => 'Діалог скинуто.',
                ],
            ),
            new SystemTranslationEntry(
                key: 'commands.cancel.response',
                group: 'commands',
                description: [
                    'en' => 'Sent after the built-in /cancel command terminates the active session.',
                    'ru' => 'Отправляется после того, как встроенная команда /cancel завершает активную сессию.',
                    'uk' => 'Надсилається після того, як вбудована команда /cancel завершує активну сесію.',
                ],
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
                description: [
                    'en' => 'Shown when a contact resumes a conversation whose session has already expired.',
                    'ru' => 'Показывается, когда контакт возобновляет диалог, сессия которого уже истекла.',
                    'uk' => 'Показується, коли контакт відновлює розмову, сесія якої вже завершилась.',
                ],
                defaults: [
                    'en' => 'Your session has expired. Please start over.',
                    'ru' => 'Сессия истекла. Пожалуйста, начните заново.',
                    'uk' => 'Сесія закінчилася. Будь ласка, почніть заново.',
                ],
            ),
            new SystemTranslationEntry(
                key: 'errors.fallback',
                group: 'errors',
                description: [
                    'en' => 'Generic fallback when the bot cannot match incoming text to any active flow or command.',
                    'ru' => 'Стандартный ответ, когда бот не может сопоставить входящий текст ни с одним активным сценарием или командой.',
                    'uk' => 'Стандартна відповідь, коли бот не може зіставити вхідний текст з жодним активним сценарієм або командою.',
                ],
                defaults: [
                    'en' => 'Sorry, I did not understand that.',
                    'ru' => 'Извините, я не понял.',
                    'uk' => 'Вибачте, я не зрозумів.',
                ],
            ),
            new SystemTranslationEntry(
                key: 'errors.flow_not_found',
                group: 'errors',
                description: [
                    'en' => 'Sent when a command tries to start a flow that no longer exists or is inactive.',
                    'ru' => 'Отправляется, когда команда пытается запустить сценарий, которого больше нет или который неактивен.',
                    'uk' => 'Надсилається, коли команда намагається запустити сценарій, якого більше немає або який неактивний.',
                ],
                defaults: [
                    'en' => 'This action is not available right now.',
                    'ru' => 'Это действие сейчас недоступно.',
                    'uk' => 'Ця дія зараз недоступна.',
                ],
            ),
            new SystemTranslationEntry(
                key: 'errors.busy',
                group: 'errors',
                description: [
                    'en' => 'Sent when an inbound message is dropped because the session is locked or paused. Overridden by assistant.busy_message when configured.',
                    'ru' => 'Отправляется, когда входящее сообщение отклоняется из-за блокировки или паузы сессии. Переопределяется полем assistant.busy_message, если оно настроено.',
                    'uk' => 'Надсилається, коли вхідне повідомлення відхиляється через блокування або паузу сесії. Замінюється полем assistant.busy_message, якщо воно налаштоване.',
                ],
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
                description: [
                    'en' => 'Default label for affirmative confirmation buttons.',
                    'ru' => 'Стандартная подпись для кнопок утвердительного подтверждения.',
                    'uk' => 'Стандартна підпис для кнопок стверджувального підтвердження.',
                ],
                defaults: ['en' => 'Yes', 'ru' => 'Да', 'uk' => 'Так'],
            ),
            new SystemTranslationEntry(
                key: 'buttons.no',
                group: 'buttons',
                description: [
                    'en' => 'Default label for negative confirmation buttons.',
                    'ru' => 'Стандартная подпись для кнопок отрицательного подтверждения.',
                    'uk' => 'Стандартна підпис для кнопок заперечного підтвердження.',
                ],
                defaults: ['en' => 'No', 'ru' => 'Нет', 'uk' => 'Ні'],
            ),
            new SystemTranslationEntry(
                key: 'buttons.confirm',
                group: 'buttons',
                description: [
                    'en' => 'Default label for explicit confirmation buttons.',
                    'ru' => 'Стандартная подпись для кнопок явного подтверждения.',
                    'uk' => 'Стандартна підпис для кнопок явного підтвердження.',
                ],
                defaults: ['en' => 'Confirm', 'ru' => 'Подтвердить', 'uk' => 'Підтвердити'],
            ),
            new SystemTranslationEntry(
                key: 'buttons.cancel',
                group: 'buttons',
                description: [
                    'en' => 'Default label for cancel buttons.',
                    'ru' => 'Стандартная подпись для кнопок отмены.',
                    'uk' => 'Стандартна підпис для кнопок скасування.',
                ],
                defaults: ['en' => 'Cancel', 'ru' => 'Отмена', 'uk' => 'Скасувати'],
            ),
            new SystemTranslationEntry(
                key: 'buttons.back',
                group: 'buttons',
                description: [
                    'en' => 'Default label for back navigation buttons.',
                    'ru' => 'Стандартная подпись для кнопок навигации «Назад».',
                    'uk' => 'Стандартна підпис для кнопок навігації «Назад».',
                ],
                defaults: ['en' => 'Back', 'ru' => 'Назад', 'uk' => 'Назад'],
            ),
        ];
    }
}
