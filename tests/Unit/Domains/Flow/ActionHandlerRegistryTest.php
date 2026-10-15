<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Infrastructure\Flow\ContainerActionHandlerFactory;
use Fapost\Foundation\Action\ActionHandlerInterface;
use Fapost\Foundation\Flow\Call\CallContext;
use LogicException;
use stdClass;
use Tests\TestCase;

final class ActionHandlerRegistryTest extends TestCase
{
    public function test_core_actions_may_use_the_core_and_platform_namespaces(): void
    {
        $registry = $this->registry();

        $this->assertSame('core.stub', $registry->register(CoreStubAction::class));
        $this->assertSame('platform.stub', $registry->register(PlatformStubAction::class));
        $this->assertNull($registry->ownerOf('core.stub'));
        $this->assertSame(['core.stub', 'platform.stub'], $registry->ids());
    }

    public function test_a_core_action_outside_the_reserved_namespaces_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Core action 'feedback.stub'");

        $this->registry()->register(OutsideStubAction::class);
    }

    public function test_the_same_action_outside_the_namespaces_is_fine_for_a_solution(): void
    {
        $registry = $this->registry();
        $registry->register(OutsideStubAction::class, 'feedback');

        $this->assertSame('feedback', $registry->ownerOf('feedback.stub'));
    }

    public function test_a_duplicate_id_names_both_registrations_and_their_owners(): void
    {
        $registry = $this->registry();
        $registry->register(CoreStubAction::class);

        try {
            $registry->register(CoreStubDuplicateAction::class, 'feedback');
            $this->fail('A duplicate id must be rejected.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString("'core.stub' already registered", $exception->getMessage());
            $this->assertStringContainsString(CoreStubAction::class . ' (owner: Core)', $exception->getMessage());
            $this->assertStringContainsString(CoreStubDuplicateAction::class . ' (owner: Solution "feedback")', $exception->getMessage());
        }
    }

    public function test_a_class_that_is_not_an_action_handler_is_rejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is not an action handler');

        $this->registry()->register(stdClass::class);
    }

    public function test_nothing_can_be_registered_after_the_freeze(): void
    {
        $registry = $this->registry();
        $registry->freeze();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('frozen');

        $registry->register(CoreStubAction::class);
    }

    public function test_a_handler_is_built_on_every_call_and_never_kept(): void
    {
        $registry = $this->registry();
        $registry->register(CoreStubAction::class);

        $this->assertNotSame($registry->get('core.stub'), $registry->get('core.stub'));
    }

    public function test_an_unknown_id_is_an_error(): void
    {
        $registry = $this->registry();

        $this->assertFalse($registry->has('core.missing'));
        $this->expectException(LogicException::class);

        $registry->get('core.missing');
    }
    private function registry(): ActionHandlerRegistry
    {
        return new ActionHandlerRegistry(new ContainerActionHandlerFactory($this->app));
    }
}

abstract class StubAction implements ActionHandlerInterface
{
    abstract public function id(): string;

    public function version(): int
    {
        return 1;
    }

    public function handle(array $parameters, CallContext $context): mixed
    {
        return null;
    }
}

final class CoreStubAction extends StubAction
{
    public function id(): string
    {
        return 'core.stub';
    }
}

final class CoreStubDuplicateAction extends StubAction
{
    public function id(): string
    {
        return 'core.stub';
    }
}

final class PlatformStubAction extends StubAction
{
    public function id(): string
    {
        return 'platform.stub';
    }
}

final class OutsideStubAction extends StubAction
{
    public function id(): string
    {
        return 'feedback.stub';
    }
}
