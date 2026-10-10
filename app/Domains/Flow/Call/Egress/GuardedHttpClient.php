<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call\Egress;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * The only way flow code makes an HTTP request whose target a tenant can influence.
 *
 * Builds a pending request from the shared HTTP factory (so `Http::fake()` keeps
 * working) with the {@see EgressGuardMiddleware} installed and these options:
 *  - redirects stay on, capped, http and https only; the guard re-checks every hop;
 *  - the proxy option is always set, so `HTTP_PROXY` / `HTTPS_PROXY` from the
 *    environment cannot route the request around the guard; only the operator's
 *    `FLOW_EGRESS_PROXY` applies;
 *  - curl itself is limited to http and https, including after a redirect.
 *
 * The pin that defeats DNS rebinding is a curl option, so the guard cannot work
 * without ext-curl and refuses to start instead.
 *
 * An architecture test forbids the rest of the Flow domain from reaching the raw
 * HTTP client, so a new transport cannot skip this class by accident.
 */
final readonly class GuardedHttpClient
{
    public function __construct(
        private HttpFactory $http,
        private EgressGuardMiddleware $guard,
        private EgressPolicy $policy,
    ) {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('The egress guard pins connections through curl; ext-curl is required.');
        }
    }

    public function request(): PendingRequest
    {
        return $this->http
            ->withMiddleware($this->guard)
            ->withOptions([
                'allow_redirects' => [
                    'max'       => $this->policy->maxRedirects,
                    'protocols' => ['http', 'https'],
                    'strict'    => false,
                    'referer'   => false,
                ],
                'proxy' => $this->policy->proxy ?? '',
                'curl'  => [
                    CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                ],
            ]);
    }
}
