<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Api;

use App\Domains\Flow\Api\ApiFireKey;
use App\Domains\Flow\Api\ApiTriggerConfig;
use App\Domains\Flow\DTOs\FlowRunOrigin;
use App\Domains\Flow\Webhook\InboundFireKey;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An API trigger's config is `{response_mode}` only, and its fire keys are told apart from every other kind.
 */
final class ApiTriggerConfigTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidConfigs(): array
    {
        return [
            'the old route key'        => [['route_key' => 'start']],
            'the old allowed sources'  => [['allowed_sources' => []]],
            'a secret'                 => [['response_mode' => 'async', 'secret' => 'x']],
            'an unknown mode'          => [['response_mode' => 'later']],
            'a mode of the wrong type' => [['response_mode' => true]],
        ];
    }
    public function test_the_response_mode_defaults_to_async_and_may_be_sync(): void
    {
        $this->assertFalse(ApiTriggerConfig::fromConfig([])->isSync());
        $this->assertFalse(ApiTriggerConfig::fromConfig(['response_mode' => 'async'])->isSync());
        $this->assertTrue(ApiTriggerConfig::fromConfig(['response_mode' => 'sync'])->isSync());
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('invalidConfigs')]
    public function test_an_unknown_key_or_response_mode_is_refused(array $config): void
    {
        $this->expectException(InvalidArgumentException::class);

        ApiTriggerConfig::fromConfig($config);
    }

    public function test_a_key_is_scoped_to_its_token_and_hashed_when_too_long(): void
    {
        $token = '01JTOKENID0000000000000000';

        $this->assertSame('api:01jtokenid0000000000000000:order-42', ApiFireKey::fromIdempotencyKey($token, 'order-42'));
        $this->assertNotSame(ApiFireKey::fromIdempotencyKey($token, 'k'), ApiFireKey::fromIdempotencyKey('01JOTHER00000000000000000', 'k'));

        $long = ApiFireKey::fromIdempotencyKey($token, str_repeat('k', 255));
        $this->assertSame('api:sha256:' . hash('sha256', mb_strtolower($token) . ':' . str_repeat('k', 255)), $long);
        $this->assertLessThanOrEqual(FlowRunOrigin::MAX_FIRE_KEY_LENGTH, mb_strlen($long));
    }

    public function test_only_printable_ascii_keys_of_1_to_255_characters_are_valid(): void
    {
        $this->assertTrue(ApiFireKey::isValidIdempotencyKey('a'));
        $this->assertTrue(ApiFireKey::isValidIdempotencyKey(str_repeat('~', 255)));
        $this->assertFalse(ApiFireKey::isValidIdempotencyKey(''));
        $this->assertFalse(ApiFireKey::isValidIdempotencyKey(str_repeat('a', 256)));
        $this->assertFalse(ApiFireKey::isValidIdempotencyKey("a\nb"));
        $this->assertFalse(ApiFireKey::isValidIdempotencyKey('ключ'));
    }

    public function test_only_a_webhook_or_api_fire_key_has_a_caller_to_answer(): void
    {
        $this->assertTrue(InboundFireKey::isInbound('wh:delivery-1'));
        $this->assertTrue(InboundFireKey::isInbound(ApiFireKey::fresh('01jrequest')));
        $this->assertFalse(InboundFireKey::isInbound('schedule:2026-11-23T10:00'));
        $this->assertFalse(InboundFireKey::isInbound('rerun:01jsession:1'));
        $this->assertFalse(InboundFireKey::isInbound('01jevent'));
    }
}
