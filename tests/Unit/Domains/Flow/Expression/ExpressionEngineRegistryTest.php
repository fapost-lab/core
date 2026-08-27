<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Expression;

use App\Domains\Flow\Expression\ExpressionEngineRegistry;
use Fapost\Foundation\Flow\Contracts\ExpressionEngineInterface;
use Fapost\Foundation\Flow\Contracts\ExpressionEngineNotFoundException;
use Fapost\Foundation\Flow\DTO\ExpressionContext;
use LogicException;
use Tests\TestCase;

final class ExpressionEngineRegistryTest extends TestCase
{
    public function test_register_and_get(): void
    {
        $registry = new ExpressionEngineRegistry();
        $engine   = new FakeEngine('eng_a', 1);

        $registry->register($engine);

        $this->assertTrue($registry->has('eng_a'));
        $this->assertSame($engine, $registry->get('eng_a'));
        $this->assertSame(['eng_a'], $registry->ids());
    }

    public function test_get_throws_for_unknown_id(): void
    {
        $registry = new ExpressionEngineRegistry();

        $this->expectException(ExpressionEngineNotFoundException::class);
        $this->expectExceptionMessageMatches("/Expression engine 'missing' is not registered/");

        $registry->get('missing');
    }

    public function test_register_throws_on_duplicate_id(): void
    {
        $registry = new ExpressionEngineRegistry();
        $registry->register(new FakeEngine('eng_a', 1));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches("/Expression engine 'eng_a' already registered/");

        $registry->register(new FakeEngine('eng_a', 2));
    }

    public function test_register_throws_after_freeze(): void
    {
        $registry = new ExpressionEngineRegistry();
        $registry->register(new FakeEngine('eng_a', 1));
        $registry->freeze();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/frozen/');

        $registry->register(new FakeEngine('eng_b', 1));
    }
}

final class FakeEngine implements ExpressionEngineInterface
{
    public function __construct(
        private readonly string $id,
        private readonly int $version,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function evaluate(string $source, ExpressionContext $context): mixed
    {
        return $source;
    }

    public function validate(string $source): void {}

    /**
     * @return list<string>
     */
    public function extractReferences(string $source): array
    {
        return [];
    }
}
