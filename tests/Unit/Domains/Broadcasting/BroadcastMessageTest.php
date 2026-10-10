<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Broadcasting;

use App\Domains\Broadcasting\Support\BroadcastMessage;
use PHPUnit\Framework\TestCase;

final class BroadcastMessageTest extends TestCase
{
    public function test_clean_drops_blank_and_non_string_entries(): void
    {
        $this->assertSame(
            ['en' => 'Hello', 'ru' => ' Привет '],
            BroadcastMessage::clean(['en' => 'Hello', 'uk' => '   ', 'de' => '', 'ru' => ' Привет ', 'fr' => null, 'es' => 5, 0 => 'x']),
        );
    }

    public function test_clean_returns_null_for_nothing_left_or_for_a_non_map(): void
    {
        $this->assertNull(BroadcastMessage::clean(['en' => '  ']));
        $this->assertNull(BroadcastMessage::clean([]));
        $this->assertNull(BroadcastMessage::clean('Hello'));
        $this->assertNull(BroadcastMessage::clean(null));
    }

    public function test_the_base_language_needs_text_that_is_not_blank(): void
    {
        $this->assertTrue(BroadcastMessage::hasBaseLanguageText(['ru' => 'Привет'], 'ru'));
        $this->assertFalse(BroadcastMessage::hasBaseLanguageText(['en' => 'Hello'], 'ru'));
        $this->assertFalse(BroadcastMessage::hasBaseLanguageText(['ru' => "  \n"], 'ru'));
        $this->assertFalse(BroadcastMessage::hasBaseLanguageText(null, 'ru'));
    }
}
