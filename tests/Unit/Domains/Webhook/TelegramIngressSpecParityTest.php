<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Channels\Telegram\TelegramAdapter;
use App\Domains\Channels\Telegram\TelegramInboundNormalizer;
use Fapost\Foundation\Channel\Ingress\IngressSpecExecutor;
use Fapost\Foundation\Channel\Ingress\SignedRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pins Telegram's declarative ingress rules, now the only form they take: the spec is what Core and the ingress
 * gateway both execute, so a verdict or a key that moves here moves in production.
 *
 * Drift is silent in both directions: a spec that is too lax opens a hole in the gateway, one that is too strict drops
 * real messages. The cases are the ones the adapter's own verification code answered before it was removed.
 */
final class TelegramIngressSpecParityTest extends TestCase
{
    private const string SECRET = 'channel-secret';

    private const string HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    /**
     * @return array<string, array{0: array<string, string>, 1: bool}>
     */
    public static function signatureCases(): array
    {
        return [
            'exact match'        => [[self::HEADER => self::SECRET], true],
            'lower case header'  => [['x-telegram-bot-api-secret-token' => self::SECRET], true],
            'wrong secret'       => [[self::HEADER => 'forged'], false],
            'empty secret'       => [[self::HEADER => ''], false],
            'missing header'     => [[], false],
            'secret with prefix' => [[self::HEADER => self::SECRET . 'x'], false],
            'whitespace padded'  => [[self::HEADER => ' ' . self::SECRET], false],
        ];
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function idempotencyCases(): array
    {
        return [
            'integer update id' => [['update_id' => 987654], 'tg:channel-42:987654'],
            'zero update id'    => [['update_id' => 0], 'tg:channel-42:0'],
            'string update id'  => [['update_id' => '123'], 'tg:channel-42:123'],
            'absent update id'  => [['message' => ['text' => 'hi']], 'tg:channel-42:'],
            'empty body'        => [[], 'tg:channel-42:'],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('signatureCases')]
    public function test_signature_verdict_follows_the_spec(array $headers, bool $expected): void
    {
        $verdict = (new IngressSpecExecutor())->verify(
            $this->adapter()->ingressSpec(),
            SignedRequest::create('{"update_id":1}', $headers),
            self::SECRET,
        );

        $this->assertSame($expected, $verdict);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('idempotencyCases')]
    public function test_idempotency_key_follows_the_spec(array $body, string $expected): void
    {
        $key = (new IngressSpecExecutor())->idempotencyKey(
            $this->adapter()->ingressSpec(),
            SignedRequest::create((string) json_encode($body, JSON_THROW_ON_ERROR)),
            'channel-42',
        );

        $this->assertSame($expected, $key);
    }

    private function adapter(): TelegramAdapter
    {
        return new TelegramAdapter($this->app->make(TelegramInboundNormalizer::class));
    }
}
