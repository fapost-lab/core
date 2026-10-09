<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels;

use App\Domains\Channels\SecretRedactor;
use PHPUnit\Framework\TestCase;

final class SecretRedactorTest extends TestCase
{
    public function test_masks_the_secret_wherever_it_appears(): void
    {
        $redacted = SecretRedactor::redact('failed for abc123 and again abc123', 'abc123');

        $this->assertSame('failed for *** and again ***', $redacted);
    }

    public function test_masks_the_url_encoded_secret(): void
    {
        $redacted = SecretRedactor::redact('GET /x?t=a%3Ab%2Fc', 'a:b/c');

        $this->assertSame('GET /x?t=***', $redacted);
    }

    public function test_masks_the_bot_segment_of_a_url_even_for_an_unknown_token(): void
    {
        $redacted = SecretRedactor::redact(
            'cURL error 6: Could not resolve host for https://api.telegram.org/bot123456:AA-bb_CC/getMe',
        );

        $this->assertSame('cURL error 6: Could not resolve host for https://api.telegram.org/bot***/getMe', $redacted);
    }

    public function test_masks_the_bot_segment_of_a_file_download_url(): void
    {
        $redacted = SecretRedactor::redact('https://api.telegram.org/file/bot123:AA/photos/1.jpg');

        $this->assertSame('https://api.telegram.org/file/bot***/photos/1.jpg', $redacted);
    }

    public function test_masks_the_longer_secret_whole_when_one_contains_another(): void
    {
        $redacted = SecretRedactor::redact('value abcdef', 'abc', 'abcdef');

        $this->assertSame('value ***', $redacted);
    }

    public function test_leaves_text_alone_when_there_is_nothing_to_mask(): void
    {
        $this->assertSame('plain text', SecretRedactor::redact('plain text', ''));
    }
}
