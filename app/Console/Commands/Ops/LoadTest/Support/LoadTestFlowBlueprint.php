<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops\LoadTest\Support;

/**
 * The single flow every seeded load-test assistant publishes: prompt for a
 * code, store whatever text comes back on the contact, echo it, end.
 *
 * The prompt/confirm text is fixed and known ahead of time so
 * {@see LoadTestSessionVerifier} and {@code loadtest:verify} can classify
 * every outbound stub message without guessing — anything that is neither
 * the prompt nor a confirm carrying the contact's own code is either a
 * cross-contact leak or a "busy" drop notice.
 *
 * Kept as a single source of truth so `loadtest:seed` (which publishes the
 * flow) and `loadtest:verify` (which classifies stub output) can never drift
 * apart on the literal text.
 */
final class LoadTestFlowBlueprint
{
    /** Contact attribute the `input` node writes the code into (`contacts.attributes.loadtest_code`). */
    final public const string ATTRIBUTE_NAME = 'loadtest_code';

    final public const string PROMPT_TEXT = 'Load test: reply with your code.';

    /** The confirm message always starts with this — the code itself makes the rest unique per contact. */
    final public const string CONFIRM_PREFIX = 'Load test accepted: ';

    public static function confirmText(): string
    {
        return self::CONFIRM_PREFIX . '{{ contact.' . self::ATTRIBUTE_NAME . ' }}';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function nodes(): array
    {
        return [
            [
                'id'      => 'send-prompt',
                'type'    => 'send_message',
                'version' => 1,
                'config'  => [
                    'content_type' => 'text',
                    'text'         => self::PROMPT_TEXT,
                ],
            ],
            [
                'id'      => 'input-code',
                'type'    => 'input',
                'version' => 1,
                'config'  => [
                    'expected_type' => 'text',
                    'variable'      => [
                        'name'    => self::ATTRIBUTE_NAME,
                        'storage' => 'contact',
                        'type'    => 'text',
                        'group'   => null,
                    ],
                ],
            ],
            [
                'id'      => 'send-confirm',
                'type'    => 'send_message',
                'version' => 1,
                'config'  => [
                    'content_type' => 'text',
                    'text'         => self::confirmText(),
                ],
            ],
            [
                'id'      => 'end-ok',
                'type'    => 'end',
                'version' => 1,
                'config'  => [
                    'status' => 'success',
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function edges(): array
    {
        return [
            ['id' => 'e1', 'from' => 'send-prompt', 'to' => 'input-code', 'handle' => 'default'],
            ['id' => 'e2', 'from' => 'input-code', 'to' => 'send-confirm', 'handle' => 'default'],
            ['id' => 'e3', 'from' => 'send-confirm', 'to' => 'end-ok', 'handle' => 'default'],
        ];
    }
}
