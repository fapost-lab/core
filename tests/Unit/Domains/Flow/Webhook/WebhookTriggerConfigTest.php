<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Webhook;

use App\Domains\Flow\Webhook\WebhookFireKey;
use App\Domains\Flow\Webhook\WebhookTriggerConfig;
use Fapost\Foundation\Channel\Ingress\SignatureScheme;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a webhook trigger's config may say, and the Foundation spec it becomes.
 */
final class WebhookTriggerConfigTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refusedConfigs(): iterable
    {
        $hmac = ['verification' => 'hmac_sha256', 'signature_header' => 'X-Signature'];

        yield 'a secret in the config' => [[...$hmac, 'secret' => 'plain']];
        yield 'the old shape' => [['method' => 'POST', 'path' => '/x', 'secret' => 's']];
        yield 'no verification' => [['signature_header' => 'X-Signature']];
        yield 'query parameter scheme' => [['verification' => 'query_param', 'signature_header' => 'token']];
        yield 'no scheme at all' => [['verification' => 'none', 'signature_header' => 'X-Signature']];
        yield 'a header name with a space' => [['verification' => 'hmac_sha256', 'signature_header' => 'X Signature']];
        yield 'a prefix on a secret header' => [['verification' => 'header_equals', 'signature_header' => 'X-S', 'signature_prefix' => 'sha256=']];
        yield 'tolerance without a header' => [[...$hmac, 'timestamp_tolerance' => 60]];
        yield 'a timestamp outside the signature' => [[...$hmac, 'timestamp_header' => 'X-T']];
        yield 'a signed template without the timestamp' => [[...$hmac, 'timestamp_header' => 'X-T', 'signed_payload' => 'v0:{raw}']];
        yield 'tolerance out of range' => [[...$hmac, 'timestamp_header' => 'X-T', 'signed_payload' => '{header.X-T}.{raw}', 'timestamp_tolerance' => 7200]];
        yield 'a signed template without raw' => [[...$hmac, 'signed_payload' => 'v0:{header.X-T}']];
        yield 'literal text around the id' => [[...$hmac, 'delivery_id' => 'gh-{header.X-GitHub-Delivery}']];
        yield 'two placeholders' => [[...$hmac, 'delivery_id' => '{header.A}{header.B}']];
        yield 'the colon syntax' => [[...$hmac, 'delivery_id' => '{header:X-Delivery}']];
        yield 'the raw body as an id' => [[...$hmac, 'delivery_id' => '{raw}']];
        yield 'an unknown response mode' => [[...$hmac, 'response_mode' => 'wait']];
    }
    public function test_a_github_like_config_becomes_an_hmac_spec_with_its_timestamp_and_delivery_template(): void
    {
        $spec = WebhookTriggerConfig::fromConfig([
            'verification'        => 'hmac_sha256',
            'signature_header'    => 'X-Hub-Signature-256',
            'signature_prefix'    => 'sha256=',
            'timestamp_header'    => 'X-Timestamp',
            'timestamp_tolerance' => 120,
            'signed_payload'      => 'v0:{header.X-Timestamp}:{raw}',
            'delivery_id'         => '{header.X-GitHub-Delivery|body.id}',
            'response_mode'       => 'async',
        ])->ingressSpec();

        $this->assertSame(SignatureScheme::HmacSha256, $spec->scheme);
        $this->assertSame('X-Hub-Signature-256', $spec->parameter);
        $this->assertSame('sha256=', $spec->prefix);
        $this->assertSame('{header.X-GitHub-Delivery|body.id}', $spec->idempotencyTemplate);
        $this->assertSame(120, $spec->timestamp?->toleranceSeconds);
    }

    public function test_the_response_mode_is_async_unless_sync_is_asked_for(): void
    {
        $base = ['verification' => 'header_equals', 'signature_header' => 'X-Webhook-Secret'];

        $this->assertFalse(WebhookTriggerConfig::fromConfig($base)->isSync());
        $this->assertFalse(WebhookTriggerConfig::fromConfig([...$base, 'response_mode' => 'async'])->isSync());
        $this->assertTrue(WebhookTriggerConfig::fromConfig([...$base, 'response_mode' => 'sync'])->isSync());
    }

    public function test_a_secret_header_config_strips_the_secret_header_from_what_the_flow_sees(): void
    {
        $config = WebhookTriggerConfig::fromConfig(['verification' => 'header_equals', 'signature_header' => 'X-Webhook-Secret']);

        $this->assertSame(SignatureScheme::HeaderEquals, $config->ingressSpec()->scheme);
        $this->assertContains('x-webhook-secret', $config->strippedHeaders());
        $this->assertContains('authorization', $config->strippedHeaders());
        $this->assertContains('cookie', $config->strippedHeaders());
    }

    /**
     * @param  array<string, mixed>  $config
     */
    #[DataProvider('refusedConfigs')]
    public function test_a_config_it_cannot_run_safely_is_refused(array $config): void
    {
        $this->expectException(InvalidArgumentException::class);

        WebhookTriggerConfig::fromConfig($config);
    }

    public function test_a_fire_key_is_the_delivery_id_unless_it_is_too_long_or_not_text(): void
    {
        $this->assertSame('wh:abc-1', WebhookFireKey::fromDeliveryId('abc-1'));
        $this->assertSame('abc-1', WebhookFireKey::shownId('wh:abc-1'));

        $long = str_repeat('a', 189);
        $this->assertSame('wh:sha256:' . hash('sha256', $long), WebhookFireKey::fromDeliveryId($long));
        $this->assertSame('wh:sha256:' . hash('sha256', "\xff\xfe"), WebhookFireKey::fromDeliveryId("\xff\xfe"));
        $this->assertSame('wh:sha256:' . hash('sha256', "a\nb"), WebhookFireKey::fromDeliveryId("a\nb"));
    }
}
