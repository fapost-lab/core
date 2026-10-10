<?php

declare(strict_types=1);

namespace Tests\Architecture;

use App\Domains\Flow\Call\Egress\GuardedHttpClient;
use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * A flow node or a builder endpoint must not open an HTTP connection to a target a tenant
 * influences except through {@see GuardedHttpClient}, which refuses non-public addresses.
 *
 * Scope is the Flow domain and the builder controllers on purpose: Telegram and the captcha
 * check call hosts the operator chose and legitimately use the raw client.
 */
final class EgressGuardTest
{
    public function test_flow_code_reaches_http_only_through_the_guarded_client(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace('App\\Domains\\Flow'),
                    Selector::NoneOf(Selector::classname(GuardedHttpClient::class)),
                ),
                Selector::inNamespace('App\\Infrastructure\\Flow'),
                Selector::inNamespace('App\\Jobs\\Flow'),
                Selector::inNamespace('App\\Http\\Controllers\\Builder'),
            )
            ->shouldNotDependOn()
            ->classes(
                Selector::classname('Illuminate\\Support\\Facades\\Http'),
                Selector::classname('Illuminate\\Http\\Client\\Factory'),
                Selector::classname('GuzzleHttp\\Client'),
                Selector::classname('GuzzleHttp\\ClientInterface'),
            )
            ->because('Tenant-steered HTTP must go through GuardedHttpClient, or the egress guard is skipped (SSRF).');
    }

    public function test_flow_code_does_not_build_its_own_pending_request(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::AllOf(
                    Selector::inNamespace('App\\Domains\\Flow'),
                    Selector::NoneOf(Selector::classname(GuardedHttpClient::class)),
                ),
                Selector::inNamespace('App\\Infrastructure\\Flow'),
                Selector::inNamespace('App\\Jobs\\Flow'),
                Selector::inNamespace('App\\Http\\Controllers\\Builder'),
            )
            ->shouldNotConstruct()
            ->classes(Selector::classname('Illuminate\\Http\\Client\\PendingRequest'))
            ->because('A hand-built PendingRequest has no egress guard middleware (SSRF).');
    }
}
