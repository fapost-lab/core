<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Messaging\Telegram;

use App\Domains\Channels\Telegram\TelegramDeliveryResolver;
use App\Domains\Channels\Telegram\TelegramDocumentDeliveryAction;
use App\Domains\Channels\Telegram\TelegramPhotoDeliveryAction;
use App\Domains\Channels\Telegram\TelegramTextDeliveryAction;
use App\Domains\Channels\Telegram\TelegramVideoDeliveryAction;
use App\Domains\Channels\Telegram\TelegramVoiceDeliveryAction;
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

    public function test_resolve_returns_voice_action_for_voice_payload(): void
    {
        $resolver = $this->resolver();

        $resolved = $resolver->resolve('voice');

        $this->assertInstanceOf(TelegramVoiceDeliveryAction::class, $resolved);
    }

    public function test_resolve_returns_text_action_for_text_payload(): void
    {
        $resolver = $this->resolver();

        $resolved = $resolver->resolve('text');

        $this->assertInstanceOf(TelegramTextDeliveryAction::class, $resolved);
    }

    public function test_resolve_returns_document_action_for_document_payload(): void
    {
        $resolver = $this->resolver();

        $resolved = $resolver->resolve('document');

        $this->assertInstanceOf(TelegramDocumentDeliveryAction::class, $resolved);
    }

    public function test_resolve_returns_video_action_for_video_payload(): void
    {
        $resolver = $this->resolver();

        $resolved = $resolver->resolve('video');

        $this->assertInstanceOf(TelegramVideoDeliveryAction::class, $resolved);
    }

    private function resolver(): TelegramDeliveryResolver
    {
        return new TelegramDeliveryResolver([
            new TelegramTextDeliveryAction(),
            new TelegramPhotoDeliveryAction(),
            new TelegramDocumentDeliveryAction(),
            new TelegramVideoDeliveryAction(),
            new TelegramVoiceDeliveryAction(),
        ]);
    }
}
