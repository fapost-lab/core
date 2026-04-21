<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

final class TelegramSignatureVerifier
{
    public function verify(string $headerToken, string $secretToken): bool
    {
        return hash_equals($secretToken, $headerToken);
    }
}
