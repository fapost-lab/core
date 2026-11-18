<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Handlers\Support\OperandResolver;
use App\Domains\Flow\State\StateNamespaceRegistry;
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

    public function test_length_accessor_counts_the_characters_of_a_text(): void
    {
        $resolver = $this->makeResolver();
        $state    = ['contact' => ['name' => 'Адам']];

        $value = $resolver->resolveLegacyPath('contact.name.length', $state, $this->context());

        $this->assertSame(4, $value);
    }

    public function test_length_accessor_is_zero_for_a_missing_value_and_a_non_text_scalar(): void
    {
        $resolver = $this->makeResolver();
        $state    = ['flow' => ['count' => 7]];

        $this->assertSame(0, $resolver->resolveLegacyPath('flow.missing.length', $state, $this->context()));
        $this->assertSame(0, $resolver->resolveLegacyPath('flow.count.length', $state, $this->context()));
    }

    public function test_real_length_key_wins_over_pseudo_accessor(): void
    {
        $resolver = $this->makeResolver();
        $state    = ['flow' => ['photos' => ['length' => 'explicit']]];

        $value = $resolver->resolveLegacyPath('flow.photos.length', $state, $this->context());

        $this->assertSame('explicit', $value);
    }

    public function test_a_solution_namespace_resolves_from_state_and_an_unregistered_one_is_rejected(): void
    {
        $namespaces = new StateNamespaceRegistry();
        $namespaces->registerSolutionNamespace('feedback');
        $resolver = $this->makeResolver($namespaces);
        $state    = ['feedback' => ['last_score' => 4], 'ghost' => ['x' => 1]];

        $this->assertSame(4, $resolver->resolveLegacyPath('feedback.last_score', $state, $this->context()));

        $this->expectException(InvalidNodeConfigException::class);
        $resolver->resolveLegacyPath('ghost.x', $state, $this->context());
    }

    public function test_the_parent_of_a_contact_length_path_is_read_through_the_state_reader(): void
    {
        $reader = new class () implements \Fapost\Foundation\Flow\Contracts\ScopedStateReaderInterface {
            public function read(string $path): mixed
            {
                return 'contact.photos' === $path ? ['a', 'b'] : null;
            }
        };
        $context = new NodeExecutionContext(
            tenantId: 't',
            contactId: 'c',
            sessionId: 's',
            nodeId: 'n',
            idempotencyKey: 'k',
            platform: 'test',
            stateReader: $reader,
        );

        // The engine's state array holds no `contact.*`: without the reader this would be 0.
        $this->assertSame(2, $this->makeResolver()->resolveLegacyPath('contact.photos.length', ['flow' => []], $context));
    }

    private function makeResolver(?StateNamespaceRegistry $namespaces = null): OperandResolver
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

        return new OperandResolver($accessors, $variableResolver, $namespaces ?? new StateNamespaceRegistry());
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
