<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Health;

use App\Domains\Health\Enums\HealthState;
use App\Domains\Health\Probes\ReverbProbe;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class ReverbProbeTest extends TestCase
{
    private const string SECRET = 's3cr3t-value-that-must-not-leak';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('broadcasting.default', 'reverb');
        config()->set('broadcasting.connections.reverb', [
            'driver'  => 'reverb',
            'key'     => 'app-key',
            'secret'  => self::SECRET,
            'app_id'  => 'app-1',
            'options' => ['host' => 'reverb', 'port' => 8080, 'scheme' => 'http'],
        ]);
    }

    public function test_up_reports_connections_and_channels(): void
    {
        Http::fake([
            'reverb:8080/apps/app-1/connections*' => Http::response(['connections' => 4]),
            'reverb:8080/apps/app-1/channels*'    => Http::response(['channels' => [
                'private-tenant.1.conversations' => [],
                'presence-staff'                 => [],
            ]]),
        ]);

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Up, $result->state);
        $this->assertSame(4, $result->details['connections']);
        $this->assertSame(2, $result->details['channels']);
        $this->assertSame(['private-tenant.1.conversations', 'presence-staff'], $result->details['channel_names']);
        $this->assertSame('reverb:8080', $result->details['address']);
    }

    public function test_an_empty_channel_list_is_up_with_zero_channels(): void
    {
        Http::fake([
            '*/connections*' => Http::response(['connections' => 0]),
            '*/channels*'    => Http::response(['channels' => []]),
        ]);

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Up, $result->state);
        $this->assertSame(0, $result->details['channels']);
    }

    public function test_a_server_error_means_down(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Down, $result->state);
        $this->assertStringContainsString('HTTP 500', $result->reason);
    }

    public function test_an_unreachable_server_is_unknown_never_up_or_down(): void
    {
        Http::fake(static function (): never {
            throw new ConnectionException('cURL error 7: connection refused to http://reverb:8080/apps/app-1/connections?auth_signature=abc');
        });

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Unknown, $result->state);
        $this->assertStringContainsString('reverb:8080', $result->reason);
        $this->assertStringNotContainsString('auth_signature', $result->reason);
    }

    public function test_a_rejected_signature_is_unknown_with_a_hint_about_the_credentials(): void
    {
        Http::fake(['*' => Http::response('', 401)]);

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Unknown, $result->state);
        $this->assertStringContainsString('REVERB_APP_SECRET', $result->reason);
    }

    public function test_a_body_that_is_not_reverbs_is_unknown(): void
    {
        Http::fake(['*' => Http::response('<html>proxy</html>', 200)]);

        $this->assertSame(HealthState::Unknown, $this->probe()->check()->state);
    }

    public function test_missing_settings_are_unknown_and_named(): void
    {
        config()->set('broadcasting.connections.reverb.options.host', null);
        config()->set('broadcasting.connections.reverb.app_id', null);
        Http::fake();

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Unknown, $result->state);
        $this->assertStringContainsString('REVERB_APP_ID, REVERB_HOST', $result->reason);
        Http::assertNothingSent();
    }

    public function test_broadcasting_switched_off_is_disabled_not_an_error(): void
    {
        config()->set('broadcasting.default', 'null');
        Http::fake();

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Disabled, $result->state);
        Http::assertNothingSent();
    }

    public function test_another_broadcast_driver_is_disabled_for_reverb(): void
    {
        config()->set('broadcasting.default', 'pusher');
        Http::fake();

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Disabled, $result->state);
        $this->assertStringContainsString('pusher', $result->reason);
    }

    public function test_the_request_is_signed_and_the_secret_is_not_sent_or_returned(): void
    {
        Http::fake([
            '*/connections*' => Http::response(['connections' => 1]),
            '*/channels*'    => Http::response(['channels' => []]),
        ]);
        Log::spy();

        $result = $this->probe()->check();

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            $signed = [
                'auth_key'       => $query['auth_key'],
                'auth_timestamp' => $query['auth_timestamp'],
                'auth_version'   => $query['auth_version'],
            ];
            $expected = hash_hmac('sha256', "GET\n{$path}\n" . http_build_query($signed), self::SECRET);

            return 'app-key' === $query['auth_key']
                && hash_equals($expected, $query['auth_signature'])
                && ! str_contains($request->url(), self::SECRET);
        });

        $this->assertStringNotContainsString(self::SECRET, json_encode($result->toArray(), JSON_THROW_ON_ERROR));
        Log::shouldNotHaveReceived('warning');
    }

    public function test_failures_do_not_carry_the_secret(): void
    {
        Http::fake(['*' => Http::response('', 401)]);

        $result = $this->probe()->check();

        $this->assertStringNotContainsString(self::SECRET, json_encode($result->toArray(), JSON_THROW_ON_ERROR));
    }

    private function probe(): ReverbProbe
    {
        return $this->app->make(ReverbProbe::class);
    }
}
