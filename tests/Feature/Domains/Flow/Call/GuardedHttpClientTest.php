<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow\Call;

use App\Domains\Flow\Call\Egress\EgressPolicy;
use App\Domains\Flow\Call\Egress\GuardedHttpClient;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Tests\TestCase;

final class GuardedHttpClientTest extends TestCase
{
    public function test_requests_carry_the_redirect_cap_the_protocol_limits_and_an_explicit_empty_proxy(): void
    {
        $options = $this->app->make(GuardedHttpClient::class)->request()->getOptions();

        $this->assertSame(
            ['max' => 5, 'protocols' => ['http', 'https'], 'strict' => false, 'referer' => false],
            $options['allow_redirects'],
        );
        // An explicit empty proxy is what stops HTTP_PROXY / HTTPS_PROXY from the environment.
        $this->assertSame('', $options['proxy']);
        $this->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options['curl'][CURLOPT_PROTOCOLS]);
        $this->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options['curl'][CURLOPT_REDIR_PROTOCOLS]);
    }

    public function test_operator_proxy_replaces_the_empty_proxy(): void
    {
        config(['flow.egress.proxy' => 'http://egress-proxy.internal:3128']);

        $options = $this->app->make(GuardedHttpClient::class)->request()->getOptions();

        $this->assertSame('http://egress-proxy.internal:3128', $options['proxy']);
    }

    public function test_policy_is_read_once_from_flow_egress_config(): void
    {
        config([
            'flow.egress.allow'         => '10.20.0.0/16,crm.internal',
            'flow.egress.max_redirects' => 3,
        ]);

        $policy = $this->app->make(EgressPolicy::class);

        $this->assertTrue($policy->allowsHost('crm.internal'));
        $this->assertTrue($policy->allowsAddress((string) inet_pton('10.20.1.1')));
        $this->assertSame(3, $policy->maxRedirects);
    }

    public function test_the_default_allowlist_is_empty(): void
    {
        $policy = $this->app->make(EgressPolicy::class);

        $this->assertSame([], $policy->allowedNetworks);
        $this->assertSame([], $policy->allowedHosts);
        $this->assertNull($policy->proxy);
    }

    public function test_an_allowlist_covering_everything_is_logged_as_a_warning(): void
    {
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $warnings = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if ('warning' === $level) {
                    $this->warnings[] = (string) $message;
                }
            }
        };
        $this->app->instance(LoggerInterface::class, $logger);
        config(['flow.egress.allow' => '0.0.0.0/0']);

        $this->app->make(EgressPolicy::class);

        $this->assertCount(1, $logger->warnings);
        $this->assertStringContainsString('effectively off', $logger->warnings[0]);
    }
}
