<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\TriggerTypeFactoryInterface;
use App\Domains\Flow\Registry\TriggerTypeRegistry;
use Fapost\Foundation\Flow\Contracts\TriggerTypeInterface;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TriggerTypeRegistryTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|null, 2: string}>
     */
    public static function refused(): array
    {
        return [
            'built-in name'           => ['webhook', null, 'is a built-in type'],
            'dotted core type'        => ['feedback.submitted', null, 'must not contain a dot'],
            'another solutions type'  => ['hr.hired', 'feedback', 'must start with "feedback."'],
            'solution type no prefix' => ['submitted', 'feedback', 'must start with "feedback."'],
            'uppercase'               => ['feedback.Submitted', 'feedback', 'snake_case'],
            'too long'                => ['feedback.' . str_repeat('a', 56), 'feedback', 'at most 64'],
        ];
    }
    public function test_a_registered_type_is_built_fresh_on_every_get_and_keeps_its_owner(): void
    {
        $registry = $this->registry(['feedback.submitted']);
        $registry->register('feedback.submitted', 'feedback');

        $this->assertTrue($registry->has('feedback.submitted'));
        $this->assertNotSame($registry->get('feedback.submitted'), $registry->get('feedback.submitted'));
        $this->assertSame('feedback', $registry->ownerOf('feedback.submitted'));
        $this->assertSame(['feedback.submitted'], $registry->types());
        $this->assertFalse($registry->has('feedback.other'));
        $this->assertNull($registry->ownerOf('feedback.other'));
    }

    public function test_a_core_type_without_a_dot_has_no_owner(): void
    {
        $registry = $this->registry(['form_submitted']);
        $registry->register('form_submitted');

        $this->assertTrue($registry->has('form_submitted'));
        $this->assertNull($registry->ownerOf('form_submitted'));
    }

    #[DataProvider('refused')]
    public function test_a_type_that_breaks_the_naming_rules_is_refused(string $type, ?string $owner, string $message): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        $this->registry([$type])->register($type, $owner);
    }

    public function test_a_duplicate_and_a_registration_after_freeze_are_refused(): void
    {
        $registry = $this->registry(['feedback.submitted', 'feedback.rated']);
        $registry->register('feedback.submitted', 'feedback');

        try {
            $registry->register('feedback.submitted', 'feedback');
            $this->fail('A duplicate was accepted.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Duplicate', $exception->getMessage());
        }

        $registry->freeze();
        $this->expectException(LogicException::class);
        $registry->register('feedback.rated', 'feedback');
    }

    public function test_getting_an_unregistered_type_throws(): void
    {
        $this->expectException(LogicException::class);

        $this->registry([])->get('feedback.submitted');
    }

    /**
     * A registry whose "classes" are the type names themselves: the factory builds a stub of that type.
     *
     * @param  list<string>  $types
     */
    private function registry(array $types): TriggerTypeRegistry
    {
        return new TriggerTypeRegistry(new class () implements TriggerTypeFactoryInterface {
            public function make(string $typeClass): TriggerTypeInterface
            {
                return new class ($typeClass) implements TriggerTypeInterface {
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

                    public function matches(array $config, array $payload): bool
                    {
                        return true;
                    }

                    public function payloadFields(): array
                    {
                        return [];
                    }
                };
            }
        });
    }
}
