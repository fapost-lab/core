<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Messaging\Telegram;

use App\Domains\Channels\Telegram\TelegramSignatureVerifier;
use Tests\TestCase;

final class TelegramSignatureVerifierTest extends TestCase
{
    public function test_valid_token_passes_verification(): void
    {
        $verifier = new TelegramSignatureVerifier();

        $this->assertTrue($verifier->verify('secret-1', 'secret-1'));
    }

    public function test_tampered_token_fails_verification(): void
    {
        $verifier = new TelegramSignatureVerifier();

        $this->assertFalse($verifier->verify('secret-1-x', 'secret-1'));
    }
}
