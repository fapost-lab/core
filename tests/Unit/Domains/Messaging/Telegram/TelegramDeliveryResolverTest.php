<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Messaging\Telegram;

use App\Domains\Channels\Telegram\Exceptions\TelegramApiException;
use App\Domains\Channels\Telegram\TelegramDeliveryResolver;
use App\Domains\Channels\Telegram\TelegramDocumentDeliveryAction;
use App\Domains\Channels\Telegram\TelegramPhotoDeliveryAction;
use App\Domains\Channels\Telegram\TelegramTextDeliveryAction;
use Tests\TestCase;

final class TelegramDeliveryResolverTest extends TestCase
{
    public function test_resolve_returns_text_action_for_keyboard_payload(): void
    {
        $resolver = $this->resolver();

        $resolved = $resolver->resolve('keyboard');

        $this->assertInstanceOf(TelegramTextDeliveryAction::class, $resolved);
    }

    public function test_resolve_returns_photo_action_for_photo_payload(): void
    {
        $resolver = $this->resolver();

        $resolved = $resolver->resolve('photo');

        $this->assertInstanceOf(TelegramPhotoDeliveryAction::class, $resolved);
    }

    public function test_resolve_throws_for_unsupported_payload_type(): void
    {
        $resolver = $this->resolver();

        $this->expectException(TelegramApiException::class);
        $this->expectExceptionMessage('Unsupported Telegram payload type [voice].');

        $resolver->resolve('voice');
    }

    private function resolver(): TelegramDeliveryResolver
    {
        return new TelegramDeliveryResolver([
            new TelegramTextDeliveryAction(),
            new TelegramPhotoDeliveryAction(),
            new TelegramDocumentDeliveryAction(),
        ]);
    }
}
