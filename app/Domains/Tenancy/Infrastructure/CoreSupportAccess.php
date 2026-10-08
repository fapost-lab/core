<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Infrastructure;

use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\SupportAccessTokenStore;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use App\Domains\Tenancy\Support\TenantHost;
use DateTimeImmutable;
use DateTimeZone;
use Fapost\Foundation\Tenancy\Contracts\SupportAccessInterface;
use Fapost\Foundation\Tenancy\DTO\SupportAccessGrant;
use Fapost\Foundation\Tenancy\DTO\SupportAccessRequest;
use Fapost\Foundation\Tenancy\Exceptions\SupportAccessUnavailableException;
use Illuminate\Support\Str;

/**
 * Core's implementation of the public support access contract.
 *
 * Issues a single-use token for the tenant's platform support user. Entering is done by
 * `POST /support/enter` on the tenant's host, which redeems it. The platform decides who may ask;
 * Core records the operator it is given.
 */
final readonly class CoreSupportAccess implements SupportAccessInterface
{
    /** Path on the tenant's host that redeems a grant. */
    public const string ENTER_PATH = '/support/enter';

    public function __construct(
        private SupportAccessTokenStore $tokens,
    ) {
    }

    public function issue(SupportAccessRequest $request): SupportAccessGrant
    {
        // Host mode only: with one tenant per deployment there is no tenant host to send the browser to,
        // and no operator package to ask.
        if (true !== config('tenancy.support_access.enabled') || TenancyResolutionMode::Host !== TenancyResolutionMode::tryFrom((string) config('tenancy.resolution'))) {
            throw SupportAccessUnavailableException::disabled();
        }

        $tenantId = mb_strtolower($request->tenantId);

        // The id names a `uuid` column; any other form names no tenant.
        $tenant = Str::isUuid($tenantId) ? Tenant::on('landlord')->find($tenantId) : null;

        if (! $tenant instanceof Tenant) {
            throw SupportAccessUnavailableException::tenantNotFound($request->tenantId);
        }

        if (! $tenant->isActive()) {
            throw SupportAccessUnavailableException::tenantNotActive($request->tenantId);
        }

        $issued = $this->tokens->issue(new SupportAccessRequest(
            tenantId: $tenant->getId(),
            operatorRef: $request->operatorRef,
            operatorName: $request->operatorName,
            operatorEmail: $request->operatorEmail,
        ));

        return new SupportAccessGrant(
            enterUrl: TenantHost::urlFor($tenant, self::ENTER_PATH),
            token: $issued['token'],
            expiresAt: new DateTimeImmutable('@' . $issued['expiresAt']->getTimestamp())->setTimezone(new DateTimeZone('UTC')),
        );
    }
}
