<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Commands;

use App\Domains\Flow\Commands\CommandActionTypeRegistry;
use App\Domains\Flow\Contracts\CommandExtensionFactoryInterface;
use Fapost\Foundation\Flow\Commands\CommandActionTypeInterface;
use Fapost\Foundation\Flow\Commands\CommandContext;
use Fapost\Foundation\Flow\Commands\CommandOutcome;
use Fapost\Foundation\Flow\Commands\SolutionCommandInterface;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommandActionTypeRegistryTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function refused(): array
    {
        return [
            'built-in style name without a dot' => ['send_message', 'feedback', 'must be "<solution id>."'],
            'another solutions type'            => ['hr.hired', 'feedback', 'must start with "feedback."'],
            'uppercase'                         => ['feedback.Thank', 'feedback', 'snake_case'],
            'empty name after the dot'          => ['feedback.', 'feedback', 'snake_case'],
            'too long'                          => ['feedback.' . str_repeat('a', 56), 'feedback', 'at most 64'],
        ];
    }

    public function test_a_registered_type_is_built_fresh_on_every_get_and_keeps_its_owner(): void
    {
        $registry = new CommandActionTypeRegistry($this->factory());
        $registry->register('feedback.thank', 'feedback');
        $registry->register('feedback.rate', 'feedback');

        $this->assertTrue($registry->has('feedback.thank'));
        $this->assertSame('feedback.thank', $registry->get('feedback.thank')->type());
        $this->assertNotSame($registry->get('feedback.thank'), $registry->get('feedback.thank'));
        $this->assertSame('feedback', $registry->ownerOf('feedback.thank'));
        $this->assertSame(['feedback.thank', 'feedback.rate'], $registry->types());
        $this->assertFalse($registry->has('feedback.other'));
        $this->assertNull($registry->ownerOf('feedback.other'));
    }

    #[DataProvider('refused')]
    public function test_a_type_that_breaks_the_naming_rules_is_refused(string $type, string $owner, string $message): void
    {
        $registry = new CommandActionTypeRegistry($this->factory());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        try {
            $registry->register($type, $owner);
        } finally {
            $this->assertSame([], $registry->types());
        }
    }

    public function test_a_type_of_the_maximal_length_is_accepted(): void
    {
        $type     = 'feedback.' . str_repeat('a', 55);
        $registry = new CommandActionTypeRegistry($this->factory());

        $registry->register($type, 'feedback');

        $this->assertSame(64, mb_strlen($type));
        $this->assertTrue($registry->has($type));
    }

    public function test_a_duplicate_is_refused_and_keeps_the_first_registration(): void
    {
        $registry = new CommandActionTypeRegistry($this->factory());
        $registry->register('feedback.thank', 'feedback');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Duplicate command action type registration: feedback.thank');

        $registry->register('feedback.thank', 'feedback');
    }

    public function test_registration_after_freeze_is_refused(): void
    {
        $registry = new CommandActionTypeRegistry($this->factory());
        $registry->register('feedback.thank', 'feedback');
        $registry->freeze();

        try {
            $registry->register('feedback.rate', 'feedback');
            $this->fail('A registration after freeze was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('after boot', $exception->getMessage());
        }

        $this->assertSame(['feedback.thank'], $registry->types());
    }

    public function test_getting_an_unregistered_type_throws(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Command action type not registered: feedback.thank');

        (new CommandActionTypeRegistry($this->factory()))->get('feedback.thank');
    }

    /**
     * A factory whose "classes" are the type names themselves: it builds a stub of that type.
     */
    private function factory(): CommandExtensionFactoryInterface
    {
        return new class () implements CommandExtensionFactoryInterface {
            public function makeActionType(string $typeClass): CommandActionTypeInterface
            {
                return new class ($typeClass) implements CommandActionTypeInterface {
                    public function __construct(private readonly string $name)
                    {
                    }

                    public function type(): string
                    {
                        return $this->name;
                    }

                    public function label(): string
                    {
                        return $this->name;
                    }

                    public function configSchema(): array
                    {
                        return [];
                    }

                    public function validateConfig(array $config): array
                    {
                        return [];
                    }

                    public function handle(CommandContext $context, array $config): CommandOutcome
                    {
                        return CommandOutcome::none();
                    }
                };
            }

            public function makeCommand(string $commandClass): SolutionCommandInterface
            {
                throw new LogicException('Not used.');
            }
        };
    }
}
