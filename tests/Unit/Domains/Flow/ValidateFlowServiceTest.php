<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Services\ValidateFlowService;
use FAPost\Foundation\Contracts\DataAccessorInterface;
use FAPost\Foundation\Contracts\NodeHandlerInterface;
use FAPost\Foundation\DTO\NodeExecutionContext;
use FAPost\Foundation\DTO\NodeExecutionResult;
use LogicException;
use Tests\TestCase;

final class ValidateFlowServiceTest extends TestCase
{
    public function test_validate_reports_missing_required_config_field_from_handler_schema(): void
    {
        $registry = new NodeHandlerRegistry();
        $registry->register(new RequiredConfigTestHandler());

        $service = new ValidateFlowService(
            registry: $registry,
            dataAccessors: new NullDataAccessorRegistry(),
        );

        $result = $service->execute([
            'send_1' => [
                'id'      => 'send_1',
                'type'    => 'required_config_test',
                'version' => 1,
                'config'  => [],
            ],
        ]);

        $this->assertFalse($result->valid);
        $this->assertSame('missing_config_field', $result->errors[0]->code);
        $this->assertSame('nodes.send_1.config.body', $result->errors[0]->path);
    }
}

final class RequiredConfigTestHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'required_config_test';
    }

    public function version(): int
    {
        return 1;
    }

    public function supportedVersions(): array
    {
        return [1];
    }

    public function label(): string
    {
        return 'Required Config Test';
    }

    public function category(): string
    {
        return 'Test';
    }

    public function configSchema(): array
    {
        return [
            'required' => ['body'],
        ];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}

final class NullDataAccessorRegistry implements DataAccessorRegistryInterface
{
    public function has(string $namespacePrefix): bool
    {
        return false;
    }

    public function resolve(string $namespacePrefix): DataAccessorInterface
    {
        throw new LogicException('Not used in this test.');
    }
}
