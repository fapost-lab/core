<?php

declare(strict_types=1);

namespace Tests\Support;

use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Fapost\Foundation\Tenancy\DTO\AccessNotice;
use Fapost\Foundation\Tenancy\DTO\TenantAccessState;
use Fapost\Foundation\Tenancy\Enums\AccessMode;

/**
 * {@see TenantAccessModeInterface} answering from memory, for tests: every tenant gets one state,
 * switchable between calls, and the asks are counted.
 */
final class FakeTenantAccessMode implements TenantAccessModeInterface
{
    /**
     * Tenant ids asked about, in order.
     *
     * @var list<string>
     */
    public array $asked = [];

    public function __construct(public TenantAccessState $state)
    {
    }

    public static function stopped(?AccessNotice $notice = null): self
    {
        return new self(new TenantAccessState(AccessMode::Stopped, $notice));
    }

    public static function active(): self
    {
        return new self(TenantAccessState::active());
    }

    public function stateFor(string $tenantId): TenantAccessState
    {
        $this->asked[] = $tenantId;

        return $this->state;
    }

    public function resume(): void
    {
        $this->state = TenantAccessState::active();
    }
}
