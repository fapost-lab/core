<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Channels\Telegram;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\EmptyChannelProfile;
use App\Domains\Channels\Telegram\TelegramChannelProfile;
use App\Domains\Channels\Telegram\TelegramWebhookOptions;
use Fapost\Foundation\Channel\Settings\ChannelSettingType;
use Tests\TestCase;

final class TelegramChannelProfileTest extends TestCase
{
    public function test_it_declares_the_webhook_settings_and_the_managed_bot_name(): void
    {
        $schema = (new TelegramChannelProfile())->settings();

        $this->assertSame(['allowed_updates', 'max_connections', 'bot_username'], array_map(static fn ($field): string => $field->key, $schema->fields));
        $this->assertSame(['allowed_updates', 'max_connections'], array_map(static fn ($field): string => $field->key, $schema->editable()));
        $this->assertSame(['bot_username'], $schema->managedKeys());
        $this->assertSame(ChannelSettingType::Choices, $schema->field('allowed_updates')?->type);
        $this->assertSame(TelegramWebhookOptions::ALLOWED_UPDATES, array_keys($schema->field('allowed_updates')->choices));
        $this->assertSame([1, 100, 40], [$schema->field('max_connections')?->min, $schema->field('max_connections')?->max, $schema->field('max_connections')?->default]);
        $this->assertTrue($schema->usesSecretToken);
    }

    public function test_the_secret_token_pattern_is_telegrams(): void
    {
        $pattern = '/^(?:' . (new TelegramChannelProfile())->settings()->secretTokenPattern . ')$/';

        $this->assertSame(1, preg_match($pattern, str_repeat('a', 256)));
        $this->assertSame(1, preg_match($pattern, 'Az09_-'));
        $this->assertSame(0, preg_match($pattern, str_repeat('a', 257)));
        $this->assertSame(0, preg_match($pattern, 'bad!secret'));
        $this->assertSame(0, preg_match($pattern, ''));
    }

    public function test_the_public_link_is_built_from_the_stored_bot_name(): void
    {
        $profile = new TelegramChannelProfile();
        $link    = $profile->publicLink(['bot_username' => 'acme_bot', 'max_connections' => 40]);

        $this->assertSame(['acme_bot', '@acme_bot', 'https://t.me/acme_bot'], [$link?->username, $link?->handle, $link?->url]);
        $this->assertNull($profile->publicLink([]));
        $this->assertNull($profile->publicLink(['bot_username' => '']));
        $this->assertNull($profile->publicLink(['bot_username' => 7]));
    }

    public function test_the_registry_serves_telegrams_profile_and_an_empty_one_for_the_rest(): void
    {
        $registry = $this->app->make(ChannelRegistryInterface::class);

        $this->assertInstanceOf(TelegramChannelProfile::class, $registry->profile('telegram'));
        $this->assertInstanceOf(EmptyChannelProfile::class, $registry->profile('viber'));
        $this->assertInstanceOf(EmptyChannelProfile::class, $registry->profile('nothing-serves-this'));
        $this->assertSame([], $registry->profile('viber')->settings()->fields);
        $this->assertTrue($registry->profile('viber')->settings()->usesSecretToken);
        $this->assertNull($registry->profile('viber')->publicLink(['bot_username' => 'x']));
    }
}
