<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\VariableResolverInterface;
use App\Domains\Flow\Exceptions\InvalidNodeConfigException;
use App\Domains\Flow\Exceptions\UnknownDataAccessorNamespacePrefixException;
use App\Domains\Flow\Handlers\Support\OperandResolver;
use App\Domains\Flow\State\Exceptions\ContactlessSessionException;
use App\Domains\Flow\State\Readers\ScopedStateReader;
use App\Domains\Flow\State\StateNamespaceRegistry;
use App\Domains\Flow\State\Variables\Variable;
use Fapost\Foundation\Contracts\DataAccessorInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\Flow\Contracts\ScopedStateReaderInterface;
use LogicException;
use Tests\TestCase;

/**
 * A `module.*` operand of branch, loop and auth_request is read through the engine's state reader (ADR-0002), so a
 * contactless session refuses it instead of asking the accessor for an empty contact id.
 */
final class OperandResolverModuleTest extends TestCase
{
    public function test_a_module_operand_is_read_through_the_state_reader_when_the_context_carries_one(): void
    {
        $accessor = new RecordingAccessor();
        $reader   = new class () implements ScopedStateReaderInterface {
            /** @var list<string> */
            public array $paths = [];

            public function read(string $path): mixed
            {
                $this->paths[] = $path;

                return 'Sales';
            }
        };

        $value = $this->resolver($accessor)->resolveLegacyPath('module.hr.department', [], $this->context($reader));

        $this->assertSame('Sales', $value);
        $this->assertSame(['module.hr.department'], $reader->paths);
        $this->assertSame([], $accessor->calls, 'the accessor is not asked past the reader');
    }

    public function test_a_contactless_session_refuses_a_module_operand(): void
    {
        $accessor = new RecordingAccessor();
        $registry = $this->registry($accessor);
        $reader   = new ScopedStateReader(
            sessionState: [],
            contact: null,
            accessors: $registry,
            namespaces: new StateNamespaceRegistry(),
        );

        try {
            $this->resolver($accessor)->resolveLegacyPath('module.hr.department', [], $this->context($reader, contactId: null));
            $this->fail('A contactless session must refuse a module.* operand.');
        } catch (ContactlessSessionException) {
            $this->assertSame([], $accessor->calls, 'no accessor is asked without a contact');
        }
    }

    public function test_without_a_reader_a_run_without_a_contact_refuses_a_module_operand(): void
    {
        $accessor = new RecordingAccessor();

        try {
            $this->resolver($accessor)->resolveLegacyPath('module.hr.department', [], $this->context(null, contactId: null));
            $this->fail('A run without a contact must refuse a module.* operand.');
        } catch (ContactlessSessionException) {
            $this->assertSame([], $accessor->calls, 'no accessor is asked without a contact');
        }
    }

    public function test_without_a_reader_the_accessor_is_asked_directly(): void
    {
        $accessor = new RecordingAccessor();

        $value = $this->resolver($accessor)->resolveLegacyPath('module.hr.department', [], $this->context(null));

        $this->assertSame('from-accessor', $value);
        $this->assertSame([['department', 'c', 't']], $accessor->calls);
    }

    public function test_an_unknown_module_accessor_is_still_a_config_error_with_a_reader(): void
    {
        $reader = new class () implements ScopedStateReaderInterface {
            public function read(string $path): mixed
            {
                return null;
            }
        };

        $this->expectException(InvalidNodeConfigException::class);

        $this->resolver(new RecordingAccessor())->resolveLegacyPath('module.crm.stage', [], $this->context($reader));
    }

    private function resolver(RecordingAccessor $accessor): OperandResolver
    {
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

        return new OperandResolver($this->registry($accessor), $variableResolver, new StateNamespaceRegistry());
    }

    private function registry(RecordingAccessor $accessor): DataAccessorRegistryInterface
    {
        return new readonly class ($accessor) implements DataAccessorRegistryInterface {
            public function __construct(private RecordingAccessor $accessor)
            {
            }

            public function has(string $namespacePrefix): bool
            {
                return 'module.hr' === $namespacePrefix;
            }

            public function resolve(string $namespacePrefix): DataAccessorInterface
            {
                return 'module.hr' === $namespacePrefix
                    ? $this->accessor
                    : throw new UnknownDataAccessorNamespacePrefixException($namespacePrefix);
            }
        };
    }

    private function context(?ScopedStateReaderInterface $reader, ?string $contactId = 'c'): NodeExecutionContext
    {
        return new NodeExecutionContext(
            tenantId: 't',
            contactId: $contactId,
            sessionId: 's',
            nodeId: 'n',
            idempotencyKey: 'k',
            platform: 'test',
            stateReader: $reader,
        );
    }
}

final class RecordingAccessor implements DataAccessorInterface
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    public array $calls = [];

    public function namespace(): string
    {
        return 'module.hr';
    }

    public function get(string $key, string $contactId, string $tenantId): mixed
    {
        $this->calls[] = [$key, $contactId, $tenantId];

        return 'from-accessor';
    }

    public function supportedKeys(): array
    {
        return ['department'];
    }
}
