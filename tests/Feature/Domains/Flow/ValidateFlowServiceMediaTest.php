<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Flow;

use App\Domains\Flow\Contracts\DataAccessorRegistryInterface;
use App\Domains\Flow\Contracts\FlowTriggerConfigValidatorInterface;
use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\Registry\NodeHandlerRegistry;
use App\Domains\Flow\Services\ValidateFlowService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use Fapost\Foundation\Contracts\DataAccessorInterface;
use Fapost\Foundation\Contracts\NodeHandlerInterface;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Fapost\Foundation\DTO\NodeExecutionResult;
use Illuminate\Support\Facades\DB;
use LogicException;
use Ramsey\Uuid\Uuid;
use Tests\Feature\FeatureTestCase;

final class ValidateFlowServiceMediaTest extends FeatureTestCase
{
    public function test_send_message_with_unknown_media_file_id_fails_validation(): void
    {
        $service = $this->makeService();

        $result = $service->execute([
            'n1' => [
                'id'      => 'n1',
                'type'    => 'send_message',
                'version' => 1,
                'config'  => [
                    'content_type'  => 'image',
                    'media_file_id' => Uuid::uuid4()->toString(),
                ],
            ],
        ]);

        $this->assertFalse($result->valid);
        $codes = array_column(
            array_map(fn ($e) => ['code' => $e->code], $result->errors),
            'code',
        );
        $this->assertContains('media_file_not_found', $codes);
    }

    public function test_send_message_with_existing_media_file_id_passes_validation(): void
    {
        $fileId = Uuid::uuid4()->toString();
        $blobId = Uuid::uuid4()->toString();

        DB::table('media_blobs')->insert([
            'id'           => $blobId,
            'tenant_id'    => Uuid::uuid4()->toString(),
            'content_hash' => str_repeat('a', 64),
            'storage_path' => 'test/file.jpg',
            'storage_disk' => 'local',
            'size'         => 1024,
            'mime_type'    => 'image/jpeg',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        DB::table('media_files')->insert([
            'id'         => $fileId,
            'tenant_id'  => Uuid::uuid4()->toString(),
            'blob_id'    => $blobId,
            'name'       => 'test.jpg',
            'kind'       => 'image',
            'metadata'   => '{}',
            'source'     => 'upload',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = $this->makeService();

        $result = $service->execute([
            'n1' => [
                'id'      => 'n1',
                'type'    => 'send_message',
                'version' => 1,
                'config'  => [
                    'content_type'  => 'image',
                    'media_file_id' => $fileId,
                ],
            ],
        ]);

        $codes = array_column(
            array_map(fn ($e) => ['code' => $e->code], $result->errors),
            'code',
        );
        $this->assertNotContains('media_file_not_found', $codes);
    }

    private function makeService(): ValidateFlowService
    {
        $registry = new NodeHandlerRegistry();
        $registry->register(new MediaValidationSendMessageStub());

        return new ValidateFlowService(
            registry: $registry,
            dataAccessors: new MediaValidationNullDataAccessors(),
            triggerValidator: new MediaValidationNullTriggerValidator(),
            tenantEvents: new MediaValidationNullTenantEvents(),
            tenantContext: new MediaValidationResolvedTenantContext(),
        );
    }
}

final class MediaValidationSendMessageStub implements NodeHandlerInterface
{
    public function type(): string
    {
        return 'send_message';
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
        return 'Send Message';
    }

    public function category(): string
    {
        return 'Test';
    }

    public function configSchema(): array
    {
        return [];
    }

    public function execute(array $nodeConfig, array $state, NodeExecutionContext $context): NodeExecutionResult
    {
        return NodeExecutionResult::executed();
    }
}

final class MediaValidationNullDataAccessors implements DataAccessorRegistryInterface
{
    public function has(string $namespacePrefix): bool
    {
        return false;
    }

    public function resolve(string $namespacePrefix): DataAccessorInterface
    {
        throw new LogicException('Not used.');
    }
}

final class MediaValidationNullTriggerValidator implements FlowTriggerConfigValidatorInterface
{
    public function validate(string $type, array $config): void
    {
    }
}

final class MediaValidationNullTenantEvents implements TenantEventRepositoryInterface
{
    public function getEventNamesByTenant(string $tenantId): array
    {
        return [];
    }

    public function registerEventNames(string $tenantId, array $eventNames): void
    {
    }
}

final class MediaValidationResolvedTenantContext implements TenantContextInterface
{
    public function set(TenantInterface $tenant): void
    {
    }

    public function get(): TenantInterface
    {
        throw new LogicException('Not used in this test.');
    }

    public function isResolved(): bool
    {
        return true;
    }

    public function reset(): void
    {
    }
}
