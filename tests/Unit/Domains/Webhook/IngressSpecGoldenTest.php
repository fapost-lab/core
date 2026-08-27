<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use Fapost\Foundation\Channel\Ingress\IngressSpec;
use Fapost\Foundation\Channel\Ingress\IngressSpecExecutor;
use Fapost\Foundation\Channel\Ingress\SignedRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Runs the shared ingress contract fixtures through the PHP executor.
 *
 * The same file is executed by the gateway's Go test suite. Keeping one set of
 * inputs and expected verdicts is what actually prevents the two implementations
 * from drifting — a divergence shows up as a failure on one side and a passing
 * run on the other, against identical data.
 *
 * Fixtures are data, not generated at test time: a fixture computed by the
 * implementation under test would agree with it by construction.
 */
final class IngressSpecGoldenTest extends TestCase
{
    private const string FIXTURE = 'contracts/ingress/golden.json';

    /**
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('goldenCases')]
    public function test_executor_matches_the_shared_contract(array $case): void
    {
        $spec    = IngressSpec::fromArray($case['spec']);
        $request = SignedRequest::create(
            (string) $case['request']['rawBody'],
            (array) $case['request']['headers'],
            (array) $case['request']['query'],
        );

        $executor = new IngressSpecExecutor();
        $context  = $case['note'] ?? $case['name'];

        $this->assertSame(
            $case['expect']['verified'],
            $executor->verify($spec, $request, (string) $case['secret']),
            "Signature verdict mismatch: {$context}",
        );

        if ( ! array_key_exists('idempotencyKey', $case['expect'])) {
            return;
        }

        $this->assertSame(
            $case['expect']['idempotencyKey'],
            $executor->idempotencyKey($spec, $request, (string) $case['channelId']),
            "Idempotency key mismatch: {$context}",
        );
    }

    public function test_every_scheme_is_represented_in_the_contract(): void
    {
        $schemes = array_unique(array_map(
            static fn (array $case): string => (string) $case[0]['spec']['scheme'],
            self::goldenCases(),
        ));

        foreach (['none', 'header_equals', 'hmac_sha256', 'hmac_sha1', 'query_param'] as $scheme) {
            $this->assertContains(
                $scheme,
                $schemes,
                "Scheme {$scheme} has no golden case, so no runtime is held to it.",
            );
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function goldenCases(): array
    {
        // Resolved from __DIR__ rather than base_path(): data providers run before
        // the application container is booted.
        $document = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/' . self::FIXTURE),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $cases = [];

        foreach ($document['cases'] as $case) {
            $cases[$case['name']] = [$case];
        }

        return $cases;
    }
}
