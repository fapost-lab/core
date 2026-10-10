<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use Fapost\Foundation\Quota\Contracts\LimitNoticeInterface;
use Fapost\Foundation\Quota\DTO\LimitNotice;
use Fapost\Foundation\Quota\Enums\LimitNoticeReason;

/**
 * Core's default for {@see LimitNoticeInterface}: the operator has nothing to add, so the notification
 * carries Core's own "contact the platform administrator".
 */
final class NoLimitNotice implements LimitNoticeInterface
{
    public function noticeFor(string $tenantId, string $key, string $locale, LimitNoticeReason $reason): ?LimitNotice
    {
        return null;
    }
}
