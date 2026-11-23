<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Contact;

use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Contact\Services\ContactSegmentService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;

/**
 * The two reads of segments the audience pickers and a flow's validation share.
 */
final class ContactSegmentLookupTest extends FeatureTestCase
{
    private const string TENANT_ID       = '00000000-0000-0000-0000-000000000001';
    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());
    }

    public function test_options_are_the_current_tenants_segments_by_name(): void
    {
        $zeta  = $this->segment(self::TENANT_ID, 'Zeta');
        $alpha = $this->segment(self::TENANT_ID, 'Alpha');
        $this->segment(self::OTHER_TENANT_ID, 'Foreign');

        $this->assertSame([
            ['value' => (string) $alpha->getKey(), 'label' => 'Alpha'],
            ['value' => (string) $zeta->getKey(), 'label' => 'Zeta'],
        ], $this->app->make(ContactSegmentService::class)->options());
    }

    public function test_a_segment_exists_for_its_own_tenant_only_and_by_a_well_formed_id(): void
    {
        $segment = $this->segment(self::TENANT_ID, 'Mine');
        $service = $this->app->make(ContactSegmentService::class);
        $id      = (string) $segment->getKey();

        $this->assertTrue($service->existsForTenant(self::TENANT_ID, $id));
        $this->assertTrue($service->existsForTenant(self::TENANT_ID, mb_strtoupper($id)));
        $this->assertFalse($service->existsForTenant(self::OTHER_TENANT_ID, $id));
        $this->assertFalse($service->existsForTenant(self::TENANT_ID, (string) Str::uuid()));
        $this->assertFalse($service->existsForTenant(self::TENANT_ID, 'not-a-uuid'));
    }

    private function segment(string $tenantId, string $name): ContactSegment
    {
        return ContactSegment::query()->create([
            'tenant_id' => $tenantId,
            'name'      => $name,
            'rules'     => ['match' => 'all', 'conditions' => []],
        ]);
    }
}
