<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Models\SupportAccessEntry;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Support\SupportAccessSession;
use App\Domains\Tenancy\Contracts\SupportAccessRedeemerInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * Turns a redeemed support access token into an entry: the support user plus a record the tenant can read.
 */
final class SupportAccessEntryService
{
    public function __construct(
        private readonly SupportAccessRedeemerInterface $redeemer,
        private readonly TenantContextInterface $tenantContext,
        private readonly PlatformSupportUserService $supportUsers,
    ) {
    }

    /**
     * Consumes the token for the current tenant. Null means the token is not valid; why is not said.
     *
     * @return array{user: User, entry: SupportAccessEntry}|null
     */
    public function enter(#[SensitiveParameter] string $plainToken, ?string $ip): ?array
    {
        $claim = $this->redeemer->redeem($plainToken, $this->tenantContext->get()->getId());

        if (null === $claim) {
            return null;
        }

        $user  = $this->supportUsers->ensure();
        $entry = SupportAccessEntry::query()->create([
            'operator_ref'   => $claim->operatorRef,
            'operator_name'  => $claim->operatorName,
            'operator_email' => $claim->operatorEmail,
            'ip'             => $ip,
            'entered_at'     => CarbonImmutable::now(),
        ]);

        return ['user' => $user, 'entry' => $entry];
    }

    /**
     * Closes entries whose session has necessarily ended without a logout (a lapsed cookie, a flushed
     * store): they are recorded as left when the session's hour was up. Current tenant only.
     *
     * @return int entries closed
     */
    public function closeAbandoned(): int
    {
        $closed = 0;
        $cutoff = CarbonImmutable::now()->subMinutes(SupportAccessSession::LIFETIME_MINUTES);

        SupportAccessEntry::query()
            ->whereNull('left_at')
            ->where('entered_at', '<', $cutoff)
            ->each(function (SupportAccessEntry $entry) use (&$closed): void {
                $entry->forceFill(['left_at' => $entry->entered_at->addMinutes(SupportAccessSession::LIFETIME_MINUTES)])->save();
                ++$closed;
            });

        return $closed;
    }

    /**
     * Marks the entry as left. Idempotent: the first time wins.
     */
    public function leave(string $entryId): void
    {
        SupportAccessEntry::query()
            ->whereKey($entryId)
            ->whereNull('left_at')
            ->update(['left_at' => CarbonImmutable::now()]);
    }
}
