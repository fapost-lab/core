<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Onboarding;

use App\Domains\Onboarding\Contracts\OnboardingStepInterface;
use App\Domains\Onboarding\Enums\CoreOnboardingStep;
use App\Domains\Onboarding\Enums\OnboardingOrigin;
use App\Domains\Onboarding\OnboardingBlock;
use App\Domains\Onboarding\Services\OnboardingProgress;
use App\Domains\Onboarding\Services\OnboardingRegistry;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class OnboardingRegistryTest extends TestCase
{
    public function test_blocks_come_in_the_order_of_their_origin_whatever_the_registration_order(): void
    {
        $registry = new OnboardingRegistry();
        $registry->register(new OnboardingBlock(OnboardingOrigin::Package, ['p']));
        $registry->register(new OnboardingBlock(OnboardingOrigin::Shell, ['s']));
        $registry->register(new OnboardingBlock(OnboardingOrigin::Core, ['c']));

        $this->assertSame(
            [OnboardingOrigin::Shell, OnboardingOrigin::Core, OnboardingOrigin::Package],
            array_map(static fn (OnboardingBlock $block): OnboardingOrigin => $block->origin, $registry->blocks()),
        );
    }

    public function test_an_origin_registers_once_and_a_step_is_listed_once(): void
    {
        $registry = new OnboardingRegistry();
        $registry->register(new OnboardingBlock(OnboardingOrigin::Core, ['a']));

        $this->expectException(LogicException::class);
        $registry->register(new OnboardingBlock(OnboardingOrigin::Core, ['b']));
    }

    public function test_a_step_listed_twice_in_a_block_is_refused(): void
    {
        $this->expectException(LogicException::class);
        (new OnboardingRegistry())->register(new OnboardingBlock(OnboardingOrigin::Core, ['a', 'a']));
    }

    public function test_a_frozen_registry_takes_no_more_blocks(): void
    {
        $registry = new OnboardingRegistry();
        $registry->freeze();

        $this->expectException(LogicException::class);
        $registry->register(new OnboardingBlock(OnboardingOrigin::Core, ['a']));
    }

    public function test_the_core_steps_are_in_the_order_of_the_wizard(): void
    {
        $this->assertSame(['languages', 'first_assistant'], array_map(static fn (CoreOnboardingStep $step): string => $step->value, CoreOnboardingStep::cases()));
    }

    public function test_a_failing_check_is_counted_complete_and_flagged_uncertain(): void
    {
        $registry = new OnboardingRegistry();
        $registry->register(new OnboardingBlock(OnboardingOrigin::Core, [FailingStep::class, OpenStep::class]));
        $progress = new OnboardingProgress($registry, static fn (string $class): OnboardingStepInterface => new $class(), new NullLogger());

        $report = $progress->report();

        $this->assertTrue($report->steps[0]->complete);
        $this->assertTrue($report->steps[0]->uncertain);
        $this->assertSame('open', $report->firstIncompleteRequired()?->step->key());
        $this->assertTrue($report->hasUncertain());
        $this->assertFalse($report->canComplete());
        $this->assertSame(['failing', 'open'], array_map(static fn (OnboardingStepInterface $step): string => $step->key(), $progress->steps()));
    }
}

abstract class FakeStep implements OnboardingStepInterface
{
    public function required(): bool
    {
        return true;
    }

    public function route(): string
    {
        return 'r.' . $this->key();
    }

    public function routes(): array
    {
        return [];
    }
}

final class FailingStep extends FakeStep
{
    public function key(): string
    {
        return 'failing';
    }

    public function isComplete(): bool
    {
        throw new RuntimeException('down');
    }
}

final class OpenStep extends FakeStep
{
    public function key(): string
    {
        return 'open';
    }

    public function isComplete(): bool
    {
        return false;
    }
}
