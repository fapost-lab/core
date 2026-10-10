<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Tenancy\Infrastructure\CoreTenantRenamer;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantStatus;
use App\Domains\Tenancy\Services\TenantSlugChanger;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Tenancy\Contracts\TenantRenamerInterface;
use Fapost\Foundation\Tenancy\Enums\RenameFailure;
use Fapost\Foundation\Tenancy\Enums\SlugProblem;
use Fapost\Foundation\Tenancy\Exceptions\TenantRenameFailedException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FailingTenantRepository;

/**
 * The rename contract against the real landlord tables: the slug moves, the schema stays, the slug
 * given up is kept for the tenant, and every refusal carries its reason.
 */
final class CoreTenantRenamerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.resolution' => 'host', 'tenancy.rename.redirect_days' => 30]);
    }

    /**
     * @return array<string, array{0: string, 1: SlugProblem}>
     */
    public static function invalidSlugs(): array
    {
        return [
            'malformed'     => ['Not A Slug', SlugProblem::Malformed],
            'too long'      => [str_repeat('a', 57), SlugProblem::TooLong],
            'punycode'      => ['xn--acme', SlugProblem::PunycodePrefix],
            'double hyphen' => ['ac--me', SlugProblem::ConsecutiveHyphens],
        ];
    }

    /**
     * @return array<string, array{TenantStatus}>
     */
    public static function renamableStatuses(): array
    {
        return [
            'inactive'  => [TenantStatus::Inactive],
            'suspended' => [TenantStatus::Suspended],
        ];
    }

    public function test_the_container_resolves_the_contract_to_core_implementation(): void
    {
        $this->assertInstanceOf(CoreTenantRenamer::class, $this->app->make(TenantRenamerInterface::class));
    }

    public function test_it_moves_the_slug_and_keeps_the_schema_and_the_former_slug(): void
    {
        $tenant = $this->tenant('acme');
        $before = CarbonImmutable::now();

        $renamed = $this->renamer()->rename($tenant->getId(), 'acme-new');

        $this->assertTrue($renamed->changed);
        $this->assertSame($tenant->getId(), $renamed->id);
        $this->assertSame('acme-new', $renamed->slug);
        $this->assertSame('acme', $renamed->previousSlug);

        $row = Tenant::on('landlord')->findOrFail($tenant->getId());
        $this->assertSame('acme-new', $row->slug);
        $this->assertSame('tenant_acme', $row->schema_name, 'The schema is never renamed.');

        $alias = DB::connection('landlord')->table('tenant_slug_aliases')->where('slug', 'acme')->first();
        $this->assertNotNull($alias);
        $this->assertSame($tenant->getId(), $alias->tenant_id);
        $this->assertNotNull($renamed->redirectUntil);
        $this->assertEqualsWithDelta(
            $before->addDays(30)->getTimestamp(),
            $renamed->redirectUntil->getTimestamp(),
            10,
        );
        $this->assertSame('UTC', $renamed->redirectUntil->getTimezone()->getName());
    }

    public function test_the_urls_point_at_the_new_host(): void
    {
        $renamed = $this->renamer()->rename($this->tenant('acme')->getId(), 'acme-new');

        $this->assertStringContainsString('://acme-new.' . config('tenancy.base_domain'), $renamed->url);
        $this->assertStringEndsWith('/', $renamed->url);
        $this->assertStringStartsWith($renamed->url, $renamed->loginUrl . '/');
        $this->assertStringEndsWith('/admin/login', $renamed->loginUrl);
        $this->assertStringNotContainsString('://acme.', $renamed->loginUrl);
    }

    public function test_repeating_a_rename_changes_nothing(): void
    {
        $tenant = $this->tenant('acme');
        $this->renamer()->rename($tenant->getId(), 'acme-new');
        $aliases = DB::connection('landlord')->table('tenant_slug_aliases')->count();

        $again = $this->renamer()->rename($tenant->getId(), 'acme-new');

        $this->assertFalse($again->changed);
        $this->assertSame('acme-new', $again->slug);
        $this->assertSame('acme-new', $again->previousSlug);
        $this->assertNull($again->redirectUntil);
        $this->assertSame($aliases, DB::connection('landlord')->table('tenant_slug_aliases')->count());
    }

    #[DataProvider('invalidSlugs')]
    public function test_an_invalid_slug_is_refused_with_its_problem(string $slug, SlugProblem $problem): void
    {
        $tenant = $this->tenant('acme');

        try {
            $this->renamer()->rename($tenant->getId(), $slug);
            $this->fail('Expected a refusal.');
        } catch (TenantRenameFailedException $e) {
            $this->assertSame(RenameFailure::SlugInvalid, $e->reason);
            $this->assertSame($problem, $e->problem);
            $this->assertSame($tenant->getId(), $e->tenantId);
        }

        $this->assertSame('acme', Tenant::on('landlord')->findOrFail($tenant->getId())->slug);
    }

    public function test_listed_ingress_and_platform_labels_are_reserved(): void
    {
        config([
            'tenancy.reserved_slugs'      => ['billing'],
            'tenancy.platform_subdomains' => ['ops'],
            'webhook.base_url'            => 'https://hooks.' . config('tenancy.base_domain'),
        ]);
        $tenant = $this->tenant('acme');

        foreach (['billing', 'ops', 'hooks'] as $slug) {
            $this->assertRefused(RenameFailure::SlugReserved, $tenant->getId(), $slug);
        }
    }

    public function test_a_slug_of_another_tenant_in_any_status_is_taken(): void
    {
        $tenant = $this->tenant('acme');
        $this->tenant('active-one');
        $this->tenant('pending-one', TenantStatus::Pending);
        $this->tenant('stopped-one', TenantStatus::Suspended);

        foreach (['active-one', 'pending-one', 'stopped-one'] as $slug) {
            $this->assertRefused(RenameFailure::SlugTaken, $tenant->getId(), $slug);
        }
    }

    public function test_the_original_slug_of_another_tenant_is_taken_through_its_schema(): void
    {
        $other = $this->tenant('beta');
        $this->renamer()->rename($other->getId(), 'beta-new');

        // `beta` is nobody's slug now, yet tenant_beta is still the schema of the first tenant.
        $this->assertRefused(RenameFailure::SlugTaken, $this->tenant('acme')->getId(), 'beta');
    }

    public function test_a_former_slug_of_another_tenant_is_taken(): void
    {
        $other = $this->tenant('beta');
        $other->forceFill(['schema_name' => 'tenant_something_else'])->save();
        $this->renamer()->rename($other->getId(), 'beta-new');

        $this->assertRefused(RenameFailure::SlugTaken, $this->tenant('acme')->getId(), 'beta');
    }

    public function test_a_tenant_can_take_its_own_former_slug_back(): void
    {
        $tenant = $this->tenant('acme');
        $this->renamer()->rename($tenant->getId(), 'acme-new');

        $back = $this->renamer()->rename($tenant->getId(), 'acme');

        $this->assertTrue($back->changed);
        $this->assertSame('acme', $back->slug);
        $this->assertSame('acme-new', $back->previousSlug);
        $aliases = DB::connection('landlord')->table('tenant_slug_aliases')->pluck('slug')->all();
        $this->assertSame(['acme-new'], $aliases, 'The slug taken back is no longer a former slug; the one given up now is.');
    }

    public function test_a_tenant_can_take_a_middle_former_slug_back(): void
    {
        $tenant = $this->tenant('acme');
        $this->renamer()->rename($tenant->getId(), 'acme-two');
        $this->renamer()->rename($tenant->getId(), 'acme-three');

        $this->renamer()->rename($tenant->getId(), 'acme-two');

        $aliases = DB::connection('landlord')->table('tenant_slug_aliases')->pluck('slug')->sort()->values()->all();
        $this->assertSame(['acme', 'acme-three'], $aliases);
    }

    public function test_a_pending_tenant_is_refused(): void
    {
        $this->assertRefused(RenameFailure::TenantPending, $this->tenant('acme', TenantStatus::Pending)->getId(), 'acme-new');
    }

    #[DataProvider('renamableStatuses')]
    public function test_a_tenant_that_is_not_serving_can_be_renamed(TenantStatus $status): void
    {
        $tenant = $this->tenant('acme', $status);

        $this->assertTrue($this->renamer()->rename($tenant->getId(), 'acme-new')->changed);
        $this->assertSame($status, Tenant::on('landlord')->findOrFail($tenant->getId())->status);
    }

    public function test_an_unknown_or_malformed_tenant_id_is_not_found(): void
    {
        foreach (['01900000-0000-7000-8000-000000000000', 'not-an-id', ''] as $id) {
            $this->assertRefused(RenameFailure::TenantNotFound, $id, 'acme-new');
        }
    }

    public function test_an_upper_case_tenant_id_names_the_same_tenant(): void
    {
        $tenant = $this->tenant('acme');

        $this->assertTrue($this->renamer()->rename(mb_strtoupper($tenant->getId()), 'acme-new')->changed);
    }

    public function test_it_is_unavailable_unless_tenants_are_resolved_by_host(): void
    {
        config(['tenancy.resolution' => 'single']);
        $tenant = $this->tenant('acme');

        $this->assertRefused(RenameFailure::Unavailable, $tenant->getId(), 'acme-new');
        $this->assertSame('acme', Tenant::on('landlord')->findOrFail($tenant->getId())->slug);
    }

    public function test_without_a_redirect_period_the_former_slug_is_still_reserved(): void
    {
        config(['tenancy.rename.redirect_days' => 0]);
        $tenant = $this->tenant('acme');

        $renamed = $this->renamer()->rename($tenant->getId(), 'acme-new');

        $this->assertTrue($renamed->changed);
        $this->assertNull($renamed->redirectUntil);
        $this->assertNull(DB::connection('landlord')->table('tenant_slug_aliases')->where('slug', 'acme')->value('redirect_until'));
        $this->assertRefused(RenameFailure::SlugTaken, $this->tenant('other')->getId(), 'acme');
    }

    public function test_losing_a_race_on_the_unique_index_is_a_taken_slug(): void
    {
        $tenant     = $this->tenant('acme');
        $repository = new FailingTenantRepository();
        $repository->before('renameSlug', static function (): void {
            throw new UniqueConstraintViolationException('landlord', 'update "tenants"', [], new PDOException('duplicate'));
        });

        $this->assertRefused(RenameFailure::SlugTaken, $tenant->getId(), 'acme-new', $this->renamerOn($repository));
        $this->assertSame('acme', Tenant::on('landlord')->findOrFail($tenant->getId())->slug);
        $this->assertSame(0, DB::connection('landlord')->table('tenant_slug_aliases')->count());
    }

    public function test_a_platform_failure_leaves_nothing_behind_and_keeps_its_cause(): void
    {
        $tenant     = $this->tenant('acme');
        $repository = new FailingTenantRepository();
        $repository->before('addFormerSlug', static function (): void {
            throw new RuntimeException('landlord down');
        });

        try {
            $this->renamerOn($repository)->rename($tenant->getId(), 'acme-new');
            $this->fail('Expected a failure.');
        } catch (TenantRenameFailedException $e) {
            $this->assertSame(RenameFailure::Failed, $e->reason);
            $this->assertSame('landlord down', $e->getPrevious()?->getMessage());
            $this->assertStringNotContainsString('landlord down', $e->getMessage());
        }

        $this->assertSame('acme', Tenant::on('landlord')->findOrFail($tenant->getId())->slug, 'The slug and the alias are one transaction.');
    }

    public function test_the_slug_claim_lock_is_taken_before_anything_is_checked(): void
    {
        $tenant     = $this->tenant('acme');
        $repository = new FailingTenantRepository();
        $order      = [];
        $repository->before('lockSlugClaims', static function () use (&$order): void {
            $order[] = 'lock';
        });
        $repository->before('findForRename', static function () use (&$order): void {
            $order[] = 'read';
        });

        $this->renamerOn($repository)->rename($tenant->getId(), 'acme-new');

        $this->assertSame(['lock', 'read'], $order);
    }

    private function assertRefused(RenameFailure $reason, string $tenantId, string $slug, ?TenantRenamerInterface $renamer = null): void
    {
        try {
            ($renamer ?? $this->renamer())->rename($tenantId, $slug);
            $this->fail("Expected {$reason->value}.");
        } catch (TenantRenameFailedException $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
            $this->assertTrue($reason->isInputError() || RenameFailure::Failed === $reason);
        }
    }

    private function renamer(): TenantRenamerInterface
    {
        return $this->app->make(TenantRenamerInterface::class);
    }

    private function renamerOn(FailingTenantRepository $repository): TenantRenamerInterface
    {
        $changer = new TenantSlugChanger(
            $repository,
            $this->app->make(TenantSlugPolicy::class),
            new NullLogger(),
            (int) config('tenancy.rename.redirect_days'),
        );

        return new CoreTenantRenamer($changer);
    }

    private function tenant(string $slug, TenantStatus $status = TenantStatus::Active): Tenant
    {
        return Tenant::on('landlord')->create([
            'slug'        => $slug,
            'schema_name' => 'tenant_' . str_replace('-', '_', $slug),
            'status'      => $status,
            'config'      => [],
        ]);
    }
}
