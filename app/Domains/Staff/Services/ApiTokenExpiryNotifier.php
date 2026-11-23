<?php

declare(strict_types=1);

namespace App\Domains\Staff\Services;

use App\Domains\Staff\Models\ApiToken;
use App\Domains\Staff\Notifications\ApiTokenExpiringNotification;
use App\Domains\Staff\Notifications\StaffRecipientResolver;
use Carbon\CarbonImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Warns the current tenant's administrators about API tokens that expire within {@see self::NOTICE_DAYS} days
 * (`adr-20261119-tenant-api-tokens` §9), at most once per token.
 *
 * "Once" is the token's `expiry_notified_at`, set by a conditional update before anything is sent, so two runs at once
 * cannot both notify; a token rotated away is marked when it is rotated and never warned about. Revoked and already
 * expired tokens are skipped. One failing recipient does not stop the others. Runs inside the tenant's switch.
 */
final readonly class ApiTokenExpiryNotifier
{
    public const int NOTICE_DAYS = 14;

    public function __construct(
        private StaffRecipientResolver $recipients,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param  string  $screenUrl  the tenant's token screen on its own host
     *
     * @return int how many tokens were announced
     */
    public function notifyExpiring(CarbonImmutable $now, string $screenUrl): int
    {
        $tokens = ApiToken::query()
            ->whereNull('revoked_at')
            ->whereNull('expiry_notified_at')
            ->where('expires_at', '>', $now)
            ->where('expires_at', '<=', $now->addDays(self::NOTICE_DAYS))
            ->orderBy('expires_at')
            ->get();

        $announced = 0;

        foreach ($tokens as $token) {
            $claimed = ApiToken::query()
                ->whereKey($token->getKey())
                ->whereNull('expiry_notified_at')
                ->toBase()
                ->update(['expiry_notified_at' => $now]);

            if (1 !== $claimed) {
                continue;
            }

            ++$announced;
            $this->announce($token, $screenUrl);
        }

        return $announced;
    }

    private function announce(ApiToken $token, string $screenUrl): void
    {
        $notification = new ApiTokenExpiringNotification(
            $token->name,
            $token->last_four,
            CarbonImmutable::instance($token->expires_at),
            $screenUrl,
        );

        foreach ($this->recipients->admins() as $admin) {
            try {
                $admin->notifyNow($notification);
            } catch (Throwable $exception) {
                $this->logger->warning('api token expiry notice delivery failed', [
                    'token_id'  => $token->getKey(),
                    'user_id'   => $admin->getKey(),
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }
}
