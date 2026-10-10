<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Media\Models\MediaBlob;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Infrastructure\CoreTenantUsage;
use App\Domains\Tenancy\Services\TenantUsageCounters;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Quota\Contracts\LimitRegistryInterface;
use Fapost\Foundation\Quota\Contracts\TenantUsageInterface;
use Fapost\Foundation\Quota\DTO\LimitDefinition;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

final class CoreTenantUsageTest extends FeatureTestCase
{
    private const string MAIN_ID = '00000000-0000-0000-0000-000000000001';

    private TenantUsageInterface $usage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usage = $this->app->make(TenantUsageInterface::class);
    }

    public function test_the_container_resolves_the_contract_to_core_implementation(): void
    {
        $this->assertInstanceOf(CoreTenantUsage::class, $this->usage);
    }

    public function test_it_reports_the_stored_media_bytes_of_the_tenant(): void
    {
        $this->blob('a', 100);
        $this->blob('b', 250);

        $this->assertSame(350, $this->usage->current(self::MAIN_ID, 'media_storage'));
    }

    public function test_a_tenant_without_blobs_uses_zero(): void
    {
        $this->assertSame(0, $this->usage->current(self::MAIN_ID, 'media_storage'));
    }

    public function test_the_id_is_matched_case_insensitively(): void
    {
        $this->blob('a', 7);

        $this->assertSame(7, $this->usage->current(mb_strtoupper(self::MAIN_ID), 'media_storage'));
    }

    public function test_an_unknown_or_malformed_tenant_is_not_reported(): void
    {
        $this->assertNull($this->usage->current((string) Str::uuid(), 'media_storage'));
        $this->assertNull($this->usage->current('not-an-id', 'media_storage'));
    }

    public function test_a_pending_tenant_has_no_storage_to_count(): void
    {
        $id = (string) Str::uuid();
        DB::connection('landlord')->table('tenants')->insert([
            'id'          => $id,
            'slug'        => 'pending-one',
            'schema_name' => 'schema_pending',
            'status'      => 'pending',
            'config'      => '{}',
        ]);

        $this->assertNull($this->usage->current($id, 'media_storage'));
    }

    public function test_a_key_the_platform_does_not_count_is_not_reported(): void
    {
        $this->assertNull($this->usage->current(self::MAIN_ID, 'assistants'));
        $this->assertNull($this->usage->current(self::MAIN_ID, 'unknown'));
    }

    public function test_the_callers_tenant_context_is_restored(): void
    {
        $context = $this->app->make(TenantContextInterface::class);
        $context->set(new RuntimeTenant(id: 'caller-tenant', schemaName: 'main'));

        $this->usage->current(self::MAIN_ID, 'media_storage');

        $this->assertSame('caller-tenant', $context->get()->getId());
    }

    public function test_the_callers_tenant_context_is_restored_when_counting_fails(): void
    {
        $this->app->make(LimitRegistryInterface::class)->register(
            new LimitDefinition('exploding', 'Exploding', 'things', LimitKind::Records),
        );
        $this->app->make(TenantUsageCounters::class)->register(
            'exploding',
            static fn (): int => throw new RuntimeException('count failed'),
        );

        $context = $this->app->make(TenantContextInterface::class);
        $context->set(new RuntimeTenant(id: 'caller-tenant', schemaName: 'main'));

        try {
            $this->usage->current(self::MAIN_ID, 'exploding');
            $this->fail('Expected the counter failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('count failed', $exception->getMessage());
        }

        $this->assertSame('caller-tenant', $context->get()->getId());
    }

    public function test_counters_are_registered_for_counted_keys_only(): void
    {
        $registry = $this->app->make(LimitRegistryInterface::class);
        $counters = $this->app->make(TenantUsageCounters::class);

        $registry->register(new LimitDefinition('volume', 'Volume', 'messages', LimitKind::PerPeriod));

        $this->expectException(LogicException::class);
        $counters->register('volume', static fn (): int => 0);
    }

    public function test_a_counter_needs_a_registered_key_and_is_registered_once(): void
    {
        $counters = $this->app->make(TenantUsageCounters::class);

        try {
            $counters->register('unregistered', static fn (): int => 0);
            $this->fail('Expected LogicException.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $counters->register('media_storage', static fn (): int => 0);
    }

    private function blob(string $seed, int $size): void
    {
        MediaBlob::query()->create([
            'tenant_id'    => self::MAIN_ID,
            'content_hash' => hash('sha256', $seed),
            'storage_path' => 'tenants/' . self::MAIN_ID . '/media/' . $seed . '.bin',
            'storage_disk' => 'local',
            'size'         => $size,
            'mime_type'    => 'application/octet-stream',
        ]);
    }
}
