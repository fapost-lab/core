<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Call;

use App\Domains\Credentials\Types\BasicCredentialType;
use App\Domains\Flow\Call\CallTester;
use App\Domains\Flow\Call\CallTransportRegistry;
use App\Domains\Flow\Call\Egress\EgressGuardMiddleware;
use App\Domains\Flow\Call\Egress\GuardedHttpClient;
use App\Domains\Flow\Call\Transports\HttpTransport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Domains\Credentials\CredentialsTestCase;

/**
 * The builder's test call with a saved credential: the same codes as the node, and nothing secret in the answer.
 */
final class CallTesterCredentialTest extends CredentialsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Booting the database may already have built the guard with the real DNS resolver; rebuild it on the fake.
        foreach ([CallTransportRegistry::class, HttpTransport::class, GuardedHttpClient::class, EgressGuardMiddleware::class] as $class) {
            $this->app->forgetInstance($class);
        }
    }

    public function test_the_credential_is_applied_and_an_echoed_secret_is_masked(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['echo' => self::SECRET], 200, ['X-Echo' => self::SECRET])]);
        $credential = $this->credential();

        $result = $this->tester()->run(
            ['transport' => 'http', 'target' => 'GET https://api.example.com/me', 'credential_id' => $credential->id],
            [],
        );

        $this->assertTrue($result['success']);
        $this->assertSame(['echo' => '***'], $result['body']);
        $this->assertSame('***', $result['headers']['X-Echo']);
        Http::assertSent(static fn (Request $request): bool => ['Bearer ' . self::SECRET] === $request->header('Authorization'));
    }

    public function test_an_unknown_credential_and_a_credential_on_another_transport_are_refused_without_a_call(): void
    {
        Http::fake();
        $credential = $this->credential();

        $missing     = $this->tester()->run(['transport' => 'http', 'target' => 'GET https://api.example.com/me', 'credential_id' => (string) Str::uuid()], []);
        $unsupported = $this->tester()->run(['transport' => 'handler', 'target' => 'whatever', 'credential_id' => $credential->id], []);

        $this->assertSame('credential_not_found', $missing['error_code']);
        $this->assertSame('credential_unsupported', $unsupported['error_code']);
        Http::assertNothingSent();
    }

    public function test_a_host_outside_the_list_is_refused(): void
    {
        Http::fake();

        $result = $this->tester()->run(
            ['transport' => 'http', 'target' => 'GET https://other.example.net/me', 'credential_id' => $this->credential()->id],
            [],
        );

        $this->assertSame('credential_host_denied', $result['error_code']);
        Http::assertNothingSent();
    }

    public function test_a_connection_failure_message_does_not_carry_the_key(): void
    {
        Http::fake(['api.example.com/*' => static function (Request $request): never {
            throw new ConnectionException('cURL error 7 for ' . $request->url());
        }]);
        $credential = $this->credential([
            'type'     => 'api_key_query',
            'settings' => ['parameter' => 'api_key'],
            'secret'   => ['key' => self::SECRET],
        ]);

        $result = $this->tester()->run(
            ['transport' => 'http', 'target' => 'GET https://api.example.com/me', 'credential_id' => $credential->id],
            [],
        );

        $this->assertSame('transport_failure', $result['error_code']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_a_failure_quoting_the_basic_authorization_value_comes_back_masked(): void
    {
        $encoded = base64_encode('alice:' . self::SECRET);
        Http::fake(['api.example.com/*' => static function () use ($encoded): never {
            throw new RuntimeException('client failed, header was Basic ' . $encoded);
        }]);
        $credential = $this->credential([
            'type'     => BasicCredentialType::ID,
            'settings' => ['username' => 'alice'],
            'secret'   => ['password' => self::SECRET],
        ]);

        $result = $this->tester()->run(
            ['transport' => 'http', 'target' => 'GET https://api.example.com/me', 'credential_id' => $credential->id],
            [],
        );

        $this->assertFalse($result['success']);
        $this->assertSame('test_exception', $result['error_code']);
        $this->assertSame('client failed, header was Basic ***', $result['body']);
        $this->assertStringNotContainsString($encoded, json_encode($result, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(self::SECRET, json_encode($result, JSON_THROW_ON_ERROR));
    }

    private function tester(): CallTester
    {
        return $this->app->make(CallTester::class);
    }
}
