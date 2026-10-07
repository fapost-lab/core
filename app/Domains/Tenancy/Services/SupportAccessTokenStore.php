<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\SupportAccessRedeemerInterface;
use App\Domains\Tenancy\Models\SupportAccessToken;
use App\Domains\Tenancy\ValueObjects\SupportAccessClaim;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Tenancy\DTO\SupportAccessRequest;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Issues and consumes support access tokens in the landlord `support_access_tokens` table.
 *
 * The plain token is never stored. A token is consumed with one conditional UPDATE, so of any number
 * of concurrent redemptions exactly one sees a changed row.
 */
final class SupportAccessTokenStore implements SupportAccessRedeemerInterface
{
    /** How long a token can be redeemed after it is issued. */
    public const int TTL_SECONDS = 60;

    /** Used and expired rows older than this are removed by {@see prune()}. */
    private const int RETENTION_HOURS = 24;

    /**
     * @return array{token: string, expiresAt: CarbonImmutable}
     */
    public function issue(SupportAccessRequest $request): array
    {
        $plain     = Str::random(64);
        $now       = CarbonImmutable::now('UTC');
        $expiresAt = $now->addSeconds(self::TTL_SECONDS);

        SupportAccessToken::query()->create([
            'tenant_id'      => $request->tenantId,
            'token_hash'     => hash('sha256', $plain),
            'operator_ref'   => $request->operatorRef,
            'operator_name'  => $request->operatorName,
            'operator_email' => $request->operatorEmail,
            'expires_at'     => $expiresAt,
            'created_at'     => $now,
        ]);

        return ['token' => $plain, 'expiresAt' => $expiresAt];
    }

    public function redeem(#[SensitiveParameter] string $plainToken, string $tenantId): ?SupportAccessClaim
    {
        if ('' === $plainToken) {
            return null;
        }

        $hash = hash('sha256', $plainToken);
        $now  = CarbonImmutable::now('UTC');

        $consumed = SupportAccessToken::query()
            ->where('token_hash', $hash)
            ->where('tenant_id', $tenantId)
            ->whereNull('used_at')
            ->where('expires_at', '>', $now)
            ->update(['used_at' => $now]);

        if (1 !== $consumed) {
            return null;
        }

        $row = SupportAccessToken::query()->where('token_hash', $hash)->first();

        return null === $row
            ? null
            : new SupportAccessClaim($row->operator_ref, $row->operator_name, $row->operator_email);
    }

    /**
     * Removes rows that can no longer be redeemed and are old enough to be of no use.
     */
    public function prune(): int
    {
        return SupportAccessToken::query()
            ->where('expires_at', '<', CarbonImmutable::now('UTC')->subHours(self::RETENTION_HOURS))
            ->delete();
    }
}
