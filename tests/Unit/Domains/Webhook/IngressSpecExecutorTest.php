<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use FAPost\Foundation\Channel\Ingress\IngressSpec;
use FAPost\Foundation\Channel\Ingress\IngressSpecExecutor;
use FAPost\Foundation\Channel\Ingress\SignatureScheme;
use FAPost\Foundation\Channel\Ingress\SignedRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ValueError;

/**
 * Reference semantics of ingress spec execution.
 *
 * Any non-PHP ingress runtime must reproduce every case here exactly; these
 * assertions are the contract such a runtime is written against.
 */
final class IngressSpecExecutorTest extends TestCase
{
    public function test_header_equals_accepts_matching_secret(): void
    {
        $spec = IngressSpec::headerEquals('X-Secret', 'k:{channel}');

        $this->assertTrue(
            $this->executor()->verify($spec, $this->request(headers: ['X-Secret' => 'right']), 'right'),
        );
    }

    public function test_header_equals_rejects_wrong_and_absent_secret(): void
    {
        $spec     = IngressSpec::headerEquals('X-Secret', 'k:{channel}');
        $executor = $this->executor();

        $this->assertFalse($executor->verify($spec, $this->request(headers: ['X-Secret' => 'wrong']), 'right'));
        $this->assertFalse($executor->verify($spec, $this->request(), 'right'));
    }

    public function test_header_lookup_is_case_insensitive(): void
    {
        $spec = IngressSpec::headerEquals('X-Telegram-Bot-Api-Secret-Token', 'k:{channel}');

        $this->assertTrue(
            $this->executor()->verify(
                $spec,
                $this->request(headers: ['x-telegram-bot-api-secret-token' => 'right']),
                'right',
            ),
        );
    }

    public function test_hmac_sha256_verifies_against_raw_body_with_prefix(): void
    {
        $body      = '{"b":2,"a":1}';
        $secret    = 'topsecret';
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $spec = IngressSpec::hmacSha256('X-Hub-Signature-256', 'k:{channel}', prefix: 'sha256=');

        $this->assertTrue(
            $this->executor()->verify(
                $spec,
                $this->request($body, ['X-Hub-Signature-256' => $signature]),
                $secret,
            ),
        );
    }

    /**
     * The digest must cover the bytes the provider sent. A decode/re-encode round
     * trip reorders keys and changes escaping, which would silently break HMAC —
     * this asserts the executor never takes that path.
     */
    public function test_hmac_sha256_is_computed_over_unmodified_bytes(): void
    {
        $body   = '{"b":2,"a":1,"text":"café"}';
        $secret = 'topsecret';

        $reencoded = json_encode(json_decode($body, true), JSON_THROW_ON_ERROR);
        $this->assertNotSame($body, $reencoded, 'Fixture must not survive a JSON round trip.');

        $spec = IngressSpec::hmacSha256('X-Sig', 'k:{channel}');

        $this->assertTrue(
            $this->executor()->verify(
                $spec,
                $this->request($body, ['X-Sig' => hash_hmac('sha256', $body, $secret)]),
                $secret,
            ),
        );

        $this->assertFalse(
            $this->executor()->verify(
                $spec,
                $this->request($body, ['X-Sig' => hash_hmac('sha256', (string) $reencoded, $secret)]),
                $secret,
            ),
        );
    }

    public function test_hmac_sha1_verifies_against_raw_body(): void
    {
        $body   = '{"a":1}';
        $secret = 's3cr3t';

        $spec = IngressSpec::hmacSha1('X-Sig', 'k:{channel}', prefix: 'sha1=');

        $this->assertTrue(
            $this->executor()->verify(
                $spec,
                $this->request($body, ['X-Sig' => 'sha1=' . hash_hmac('sha1', $body, $secret)]),
                $secret,
            ),
        );
    }

    public function test_query_param_scheme_compares_against_secret(): void
    {
        $spec     = IngressSpec::queryParam('token', 'k:{channel}');
        $executor = $this->executor();

        $this->assertTrue($executor->verify($spec, $this->request(query: ['token' => 'right']), 'right'));
        $this->assertFalse($executor->verify($spec, $this->request(query: ['token' => 'wrong']), 'right'));
    }

    public function test_none_scheme_accepts_any_request(): void
    {
        $this->assertTrue(
            $this->executor()->verify(IngressSpec::none('k:{channel}'), $this->request(), 'ignored'),
        );
    }

    public function test_none_scheme_needs_no_parameter(): void
    {
        $this->assertNull(IngressSpec::none('k:{channel}')->parameter);
    }

    public function test_scheme_requiring_a_parameter_rejects_an_empty_one(): void
    {
        $this->expectException(ValueError::class);

        new IngressSpec(SignatureScheme::HeaderEquals, '', '', 'k:{channel}');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('idempotencyCases')]
    public function test_idempotency_template_resolution(string $template, array $body, string $expected): void
    {
        $spec = IngressSpec::none($template);

        $this->assertSame(
            $expected,
            $this->executor()->idempotencyKey(
                $spec,
                $this->request((string) json_encode($body), ['X-Delivery' => 'd-42']),
                'channel-7',
            ),
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function idempotencyCases(): array
    {
        return [
            'channel only'      => ['c:{channel}', [], 'c:channel-7'],
            'top level scalar'  => ['tg:{channel}:{body.update_id}', ['update_id' => 99], 'tg:channel-7:99'],
            'nested path'       => ['{body.message.chat.id}', ['message' => ['chat' => ['id' => 5]]], '5'],
            'header value'      => ['h:{header.X-Delivery}', [], 'h:d-42'],
            'missing path'      => ['tg:{channel}:{body.update_id}', [], 'tg:channel-7:'],
            'path through leaf' => ['{body.a.b}', ['a' => 1], ''],
            'non scalar value'  => ['{body.message}', ['message' => ['x' => 1]], ''],
            'bool value'        => ['{body.ok}', ['ok' => true], '1'],
            'unknown namespace' => ['{cookie.sid}', [], ''],
            'no placeholders'   => ['static-key', [], 'static-key'],
        ];
    }

    public function test_malformed_body_yields_empty_placeholders_instead_of_failing(): void
    {
        $spec = IngressSpec::none('tg:{channel}:{body.update_id}');

        $this->assertSame(
            'tg:channel-7:',
            $this->executor()->idempotencyKey($spec, $this->request('not json at all'), 'channel-7'),
        );
    }

    public function test_wire_format_round_trips(): void
    {
        $spec = IngressSpec::hmacSha256('X-Hub-Signature-256', 'wa:{channel}:{body.id}', prefix: 'sha256=');

        $restored = IngressSpec::fromArray($spec->jsonSerialize());

        $this->assertEquals($spec, $restored);
    }

    public function test_wire_format_rejects_unknown_version(): void
    {
        $this->expectException(ValueError::class);

        IngressSpec::fromArray(['v' => 99, 'scheme' => 'none', 'idempotency' => 'k']);
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $query
     */
    private function request(string $rawBody = '{}', array $headers = [], array $query = []): SignedRequest
    {
        return SignedRequest::create($rawBody, $headers, $query);
    }

    private function executor(): IngressSpecExecutor
    {
        return new IngressSpecExecutor();
    }
}
