<?php

declare(strict_types=1);

namespace Tests\Support;

use Fapost\Foundation\Quota\Contracts\UsageMeterInterface;
use Fapost\Foundation\Quota\DTO\UsageDecision;
use Fapost\Foundation\Quota\DTO\UsageUnit;
use Throwable;

/**
 * {@see UsageMeterInterface} for tests: answers one decision (or throws) and keeps every unit it was asked about.
 */
final class FakeUsageMeter implements UsageMeterInterface
{
    /**
     * @var list<UsageUnit>
     */
    public array $units = [];

    public function __construct(
        public UsageDecision $decision,
        public ?Throwable $failure = null,
    ) {
    }

    public static function allowing(): self
    {
        return new self(UsageDecision::allowed());
    }

    public static function denying(int $limit = 10, int $used = 10): self
    {
        return new self(UsageDecision::refused($limit, $used, 'Limit reached.'));
    }

    public static function failing(Throwable $failure): self
    {
        return new self(UsageDecision::allowed(), $failure);
    }

    public function consume(UsageUnit $unit): UsageDecision
    {
        $this->units[] = $unit;

        if (null !== $this->failure) {
            throw $this->failure;
        }

        return $this->decision;
    }
}
