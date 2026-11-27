<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Commands;

use App\Domains\Flow\Commands\BuiltinCommandsRegistry;
use App\Domains\Flow\Commands\SolutionCommandRegistry;
use App\Domains\Flow\Contracts\CommandExtensionFactoryInterface;
use Fapost\Foundation\Flow\Commands\CommandActionTypeInterface;
use Fapost\Foundation\Flow\Commands\SolutionCommandInterface;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SolutionCommandRegistryTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refused(): array
    {
        return [
            'built-in command'     => ['/reset', 'is a built-in command'],
            'another built-in'     => ['/cancel', 'is a built-in command'],
            'no slash'             => ['feedback', 'must be "/" followed by'],
            'uppercase'            => ['/Feedback', 'must be "/" followed by'],
            'only a slash'         => ['/', 'must be "/" followed by'],
            'whitespace'           => ['/give feedback', 'must be "/" followed by'],
            'longer than 32 chars' => ['/' . str_repeat('a', 33), 'must be "/" followed by'],
        ];
    }

    public function test_a_registered_command_is_built_fresh_on_every_get_and_keeps_its_owner(): void
    {
        $registry = $this->registry();
        $registry->register('/feedback', 'feedback');
        $registry->register('/rate_us', 'feedback');

        $this->assertTrue($registry->has('/feedback'));
        $this->assertSame('/feedback', $registry->get('/feedback')->command());
        $this->assertNotSame($registry->get('/feedback'), $registry->get('/feedback'));
        $this->assertSame('feedback', $registry->ownerOf('/feedback'));
        $this->assertSame(['/feedback', '/rate_us'], $registry->commands());
        $this->assertFalse($registry->has('/other'));
        $this->assertNull($registry->ownerOf('/other'));
    }

    #[DataProvider('refused')]
    public function test_a_built_in_or_malformed_command_is_refused(string $command, string $message): void
    {
        $registry = $this->registry();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        try {
            $registry->register($command, 'feedback');
        } finally {
            $this->assertSame([], $registry->commands());
        }
    }

    public function test_a_command_of_the_maximal_length_is_accepted(): void
    {
        $registry = $this->registry();

        $registry->register('/' . str_repeat('a', 32), 'feedback');

        $this->assertCount(1, $registry->commands());
    }

    public function test_a_second_owner_of_one_command_is_refused(): void
    {
        $registry = $this->registry();
        $registry->register('/feedback', 'feedback');

        try {
            $registry->register('/feedback', 'survey');
            $this->fail('A duplicate was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Duplicate command registration: /feedback', $exception->getMessage());
        }

        $this->assertSame('feedback', $registry->ownerOf('/feedback'));
    }

    public function test_registration_after_freeze_is_refused(): void
    {
        $registry = $this->registry();
        $registry->register('/feedback', 'feedback');
        $registry->freeze();

        try {
            $registry->register('/rate', 'feedback');
            $this->fail('A registration after freeze was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('after boot', $exception->getMessage());
        }

        $this->assertSame(['/feedback'], $registry->commands());
    }

    public function test_getting_an_unregistered_command_throws(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Command not registered: /feedback');

        $this->registry()->get('/feedback');
    }

    /**
     * A registry whose "classes" are the command names themselves: the factory builds a stub of that command.
     */
    private function registry(): SolutionCommandRegistry
    {
        return new SolutionCommandRegistry(new class () implements CommandExtensionFactoryInterface {
            public function makeActionType(string $typeClass): CommandActionTypeInterface
            {
                throw new LogicException('Not used.');
            }

            public function makeCommand(string $commandClass): SolutionCommandInterface
            {
                return new class ($commandClass) implements SolutionCommandInterface {
                    public function __construct(private readonly string $name)
                    {
                    }

                    public function command(): string
                    {
                        return $this->name;
                    }

                    public function actionType(): string
                    {
                        return 'send_message';
                    }

                    public function config(): array
                    {
                        return ['text' => 'hi'];
                    }

                    public function label(): string
                    {
                        return $this->name;
                    }
                };
            }
        }, new BuiltinCommandsRegistry());
    }
}
