<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Staff\Enums\BuilderMode;
use App\Domains\Staff\Enums\UserStatus;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Services\PlatformSupportUserService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Infrastructure\CoreBuilderModeUsage;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Database\Seeders\TenantAclSeeder;
use Fapost\Foundation\Analytics\Contracts\BuilderModeUsageInterface;
use Fapost\Foundation\Quota\Contracts\TenantUsageInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

final class CoreBuilderModeUsageTest extends FeatureTestCase
{
    private const string MAIN_ID = '00000000-0000-0000-0000-000000000001';

    private BuilderModeUsageInterface $usage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->usage = $this->app->make(BuilderModeUsageInterface::class);
    }

    public function test_the_container_resolves_the_contract_to_core_implementation(): void
    {
        $this->assertInstanceOf(CoreBuilderModeUsage::class, $this->usage);
    }

    public function test_a_tenant_without_staff_has_no_one_on_either_mode(): void
    {
        $usage = $this->usage->usageFor(self::MAIN_ID);

        $this->assertNotNull($usage);
        $this->assertSame(0, $usage->tree);
        $this->assertSame(0, $usage->graph);
    }

    public function test_it_counts_each_mode_and_a_user_who_never_chose_is_on_the_tree(): void
    {
        User::factory()->create();
        User::factory()->create(['builder_mode' => BuilderMode::Tree]);
        User::factory()->count(2)->create(['builder_mode' => BuilderMode::Graph]);
        User::factory()->create(['builder_mode' => BuilderMode::Graph, 'status' => UserStatus::Pending]);

        $usage = $this->usage->usageFor(self::MAIN_ID);

        $this->assertNotNull($usage);
        $this->assertSame(2, $usage->tree);
        $this->assertSame(3, $usage->graph, 'An invited user takes a staff place and is counted.');
    }

    public function test_it_counts_only_who_the_staff_limit_counts(): void
    {
        User::factory()->create(['builder_mode' => BuilderMode::Graph]);
        User::factory()->create(['builder_mode' => BuilderMode::Graph, 'is_active' => false]);
        $this->app->make(PlatformSupportUserService::class)->ensure();

        $usage = $this->usage->usageFor(self::MAIN_ID);

        $this->assertNotNull($usage);
        $this->assertSame(0, $usage->tree, 'The platform support user is not counted.');
        $this->assertSame(1, $usage->graph, 'A deactivated account is not counted.');
        $this->assertSame(
            $this->app->make(TenantUsageInterface::class)->current(self::MAIN_ID, 'staff'),
            $usage->total,
        );
    }

    public function test_the_id_is_matched_case_insensitively(): void
    {
        User::factory()->create(['builder_mode' => BuilderMode::Graph]);

        $this->assertSame(1, $this->usage->usageFor(mb_strtoupper(self::MAIN_ID))?->graph);
    }

    public function test_an_unknown_or_malformed_tenant_is_not_reported(): void
    {
        $this->assertNull($this->usage->usageFor((string) Str::uuid()));
        $this->assertNull($this->usage->usageFor('not-an-id'));
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

        $this->assertNull($this->usage->usageFor($id));
    }

    public function test_the_callers_tenant_context_is_restored(): void
    {
        $context = $this->app->make(TenantContextInterface::class);
        $context->set(new RuntimeTenant(id: 'caller-tenant', schemaName: 'main'));

        $this->usage->usageFor(self::MAIN_ID);

        $this->assertSame('caller-tenant', $context->get()->getId());
    }

    public function test_the_callers_tenant_context_is_restored_when_counting_fails(): void
    {
        $usage = new CoreBuilderModeUsage(
            $this->app->make(TenantSwitcher::class),
            static fn (): array => throw new RuntimeException('count failed'),
        );

        $context = $this->app->make(TenantContextInterface::class);
        $context->set(new RuntimeTenant(id: 'caller-tenant', schemaName: 'main'));

        try {
            $usage->usageFor(self::MAIN_ID);
            $this->fail('Expected the count failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('count failed', $exception->getMessage());
        }

        $this->assertSame('caller-tenant', $context->get()->getId());
    }
}
