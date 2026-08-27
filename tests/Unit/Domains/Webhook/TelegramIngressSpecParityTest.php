<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Channels\Telegram\TelegramAdapter;
use App\Domains\Channels\Telegram\TelegramInboundNormalizer;
use App\Domains\Channels\Telegram\TelegramSignatureVerifier;
use FAPost\Foundation\Channel\Ingress\IngressSpecExecutor;
use FAPost\Foundation\Channel\Ingress\SignedRequest;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards the single most dangerous failure mode of declarative ingress:
 * the spec and the adapter it describes drifting apart.
 *
 * Drift is silent in both directions — a spec that is too lax opens a hole in the
 * gateway, one that is too strict drops real messages — so every fixture is run
 * through both implementations and the verdicts must match exactly.
 */
final class TelegramIngressSpecParityTest extends TestCase
{
    private const string SECRET = 'channel-secret';

    private const string HEADER = 'X-Telegram-Bot-Api-Secret-Token';

    /**
     * @param  array<string, string>  $headers
     */
    #[DataProvider('signatureCases')]
    public function test_signature_verdict_matches_adapter(array $headers, bool $expected): void
    {
        $body    = '{"update_id":1}';
        $adapter = $this->adapter();

        $viaAdapter = $adapter->verifySignature($this->httpRequest($body, $headers), self::SECRET);
        $viaSpec    = (new IngressSpecExecutor())->verify(
            $adapter->ingressSpec(),
            SignedRequest::create($body, $headers),
            self::SECRET,
        );

        $this->assertSame($expected, $viaAdapter, 'Adapter disagrees with the expected verdict.');
        $this->assertSame($viaAdapter, $viaSpec, 'Ingress spec drifted from the adapter implementation.');
    }

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
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('idempotencyCases')]
    public function test_idempotency_key_matches_adapter(array $body): void
    {
        $raw     = (string) json_encode($body, JSON_THROW_ON_ERROR);
        $adapter = $this->adapter();

        $viaAdapter = $adapter->extractIdempotencyKey($this->httpRequest($raw), 'channel-42');
        $viaSpec    = (new IngressSpecExecutor())->idempotencyKey(
            $adapter->ingressSpec(),
            SignedRequest::create($raw),
            'channel-42',
        );

        $this->assertSame($viaAdapter, $viaSpec, 'Ingress spec drifted from the adapter implementation.');
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function idempotencyCases(): array
    {
        return [
            'integer update id' => [['update_id' => 987654]],
            'zero update id'    => [['update_id' => 0]],
            'string update id'  => [['update_id' => '123']],
            'absent update id'  => [['message' => ['text' => 'hi']]],
            'empty body'        => [[]],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function httpRequest(string $body, array $headers = []): Request
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . mb_strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/webhook/telegram/hash', 'POST', server: $server, content: $body);
    }

    private function adapter(): TelegramAdapter
    {
        return new TelegramAdapter(
            new TelegramSignatureVerifier(),
            $this->app->make(TelegramInboundNormalizer::class),
        );
    }
}
