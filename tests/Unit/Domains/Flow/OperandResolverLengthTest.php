<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Handlers\Support\OperandResolver;
use App\Domains\Flow\State\Variables\Variable;
use Fapost\Foundation\Contracts\DataAccessorInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use LogicException;
use Tests\TestCase;

final class OperandResolverLengthTest extends TestCase
{
    public function test_length_accessor_returns_array_element_count(): void
    {
        $resolver = $this->makeResolver();
        $state    = ['contact' => ['photos' => ['a', 'b', 'c']]];

        $value = $resolver->resolveLegacyPath('contact.photos.length', $state, $this->context());

        $this->assertSame(3, $value);
    }

    public function test_length_accessor_yields_zero_for_empty_array(): void
    {
        $resolver = $this->makeResolver();
        $state    = ['flow' => ['answers' => []]];

        $value = $resolver->resolveLegacyPath('flow.answers.length', $state, $this->context());

        $this->assertSame(0, $value);
    }

    public function test_length_accessor_ignored_when_parent_is_not_an_array(): void
    {
        $resolver = $this->makeResolver();
        $state    = ['contact' => ['name' => 'Ada']];

        $value = $resolver->resolveLegacyPath('contact.name.length', $state, $this->context());

        $this->assertNull($value);
    }

    public function test_real_length_key_wins_over_pseudo_accessor(): void
    {
        $resolver = $this->makeResolver();
        $state    = ['flow' => ['photos' => ['length' => 'explicit']]];

        $value = $resolver->resolveLegacyPath('flow.photos.length', $state, $this->context());

        $this->assertSame('explicit', $value);
    }

    private function makeResolver(): OperandResolver
    {
        $accessors = new class () implements DataAccessorRegistryInterface {
            public function has(string $namespacePrefix): bool
            {
                return false;
            }

            public function resolve(string $namespacePrefix): DataAccessorInterface
            {
                throw new LogicException('Not used in this test.');
            }
        };

        $variableResolver = new class () implements VariableResolverInterface {
            public function resolveTargetPath(Variable $variable): string
            {
                throw new LogicException('Not used in this test.');
            }

            public function read(Variable $variable, NodeExecutionContext $context): mixed
            {
                throw new LogicException('Not used in this test.');
            }

            public function fromLegacyPath(string $path): Variable
            {
                throw new LogicException('Not used in this test.');
            }
        };

        return new OperandResolver($accessors, $variableResolver);
    }

    private function context(): NodeExecutionContext
    {
        return new NodeExecutionContext(
            tenantId: 't',
            contactId: 'c',
            sessionId: 's',
            nodeId: 'n',
            idempotencyKey: 'k',
            platform: 'test',
        );
    }
}
