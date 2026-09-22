<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops\LoadTest\Support;

/**
 * Deterministic-shape, collision-safe identifiers for synthetic load-test contacts.
 *
 * Pure and Laravel-free on purpose: {@see LoadTestSessionVerifier} and this
 * class are the two pieces of the harness worth unit testing without booting
 * the framework.
 */
final class LoadTestCodeGenerator
{
    private const int CHAT_ID_BASE  = 900_000_000;
    private const int TENANT_STRIDE = 1_000_000;

    /**
     * A synthetic Telegram chat id, unique across the whole run.
     */
    public static function chatId(int $tenantIndex, int $contactIndex): int
    {
        return self::CHAT_ID_BASE + ($tenantIndex * self::TENANT_STRIDE) + $contactIndex;
    }

    /**
     * A code that carries its owning tenant in the text itself: any code
     * found on the wrong chat, or in the wrong tenant's contact attribute,
     * is trivially a leak because the tenant slug embedded in it disagrees.
     */
    public static function code(string $tenantSlug, int $contactIndex): string
    {
        return sprintf(
            'LT-%s-%04d-%s',
            mb_strtoupper($tenantSlug),
            $contactIndex,
            mb_strtoupper(bin2hex(random_bytes(3))),
        );
    }
}
