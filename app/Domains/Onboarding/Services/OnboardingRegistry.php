<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Services;

use App\Domains\Onboarding\Enums\OnboardingOrigin;
use App\Domains\Onboarding\OnboardingBlock;
use LogicException;

/**
 * The blocks of steps the wizard knows, one per owner.
 *
 * A worker-lifetime singleton holding only class names, never a tenant's answer. Providers register while the
 * application boots, then {@see self::freeze()} closes it (outside testing), as {@see \App\Domains\Tenancy\Services\PlatformSettingRegistry} does.
 */
final class OnboardingRegistry
{
    /**
     * @var array<string, OnboardingBlock>
     */
    private array $blocks = [];

    private bool $frozen = false;

    /**
     * @throws LogicException when the registry is closed, the origin already has a block, or a step is listed twice
     */
    public function register(OnboardingBlock $block): void
    {
        if ($this->frozen) {
            throw new LogicException('OnboardingRegistry is frozen; cannot register a block.');
        }

        if (isset($this->blocks[$block->origin->value])) {
            throw new LogicException(sprintf('The "%s" block of onboarding steps is already registered.', $block->origin->value));
        }

        if (array_unique($block->steps) !== $block->steps) {
            throw new LogicException(sprintf('The "%s" block lists a step twice.', $block->origin->value));
        }

        $this->blocks[$block->origin->value] = $block;
    }

    /**
     * @return list<OnboardingBlock> in the order of {@see OnboardingOrigin}
     */
    public function blocks(): array
    {
        $ordered = [];

        foreach (OnboardingOrigin::cases() as $origin) {
            if (isset($this->blocks[$origin->value])) {
                $ordered[] = $this->blocks[$origin->value];
            }
        }

        return $ordered;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }
}
