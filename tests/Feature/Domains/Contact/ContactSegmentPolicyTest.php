<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Contact;

use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Contact\Policies\ContactSegmentPolicy;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\FeatureTestCase;

/**
 * {@see ContactSegmentPolicy}: contact permissions or ManageBroadcast, no assistant assignment.
 */
final class ContactSegmentPolicyTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_user_without_any_relevant_permission_is_denied(): void
    {
        $user    = User::factory()->create();
        $segment = $this->segment();

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', ContactSegment::class));
        $this->assertFalse(Gate::forUser($user)->allows('view', $segment));
        $this->assertFalse(Gate::forUser($user)->allows('create', ContactSegment::class));
        $this->assertFalse(Gate::forUser($user)->allows('update', $segment));
        $this->assertFalse(Gate::forUser($user)->allows('delete', $segment));
        $this->assertFalse(Gate::forUser($user)->allows('deleteAny', ContactSegment::class));
    }

    public function test_view_contacts_can_view_but_not_mutate(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ViewContacts->value);
        $segment = $this->segment();

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', ContactSegment::class));
        $this->assertTrue(Gate::forUser($user)->allows('view', $segment));
        $this->assertFalse(Gate::forUser($user)->allows('create', ContactSegment::class));
        $this->assertFalse(Gate::forUser($user)->allows('update', $segment));
        $this->assertFalse(Gate::forUser($user)->allows('delete', $segment));
    }

    public function test_manage_contacts_can_do_everything(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageContacts->value);
        $segment = $this->segment();

        $this->assertTrue(Gate::forUser($user)->allows('view', $segment));
        $this->assertTrue(Gate::forUser($user)->allows('create', ContactSegment::class));
        $this->assertTrue(Gate::forUser($user)->allows('update', $segment));
        $this->assertTrue(Gate::forUser($user)->allows('delete', $segment));
        $this->assertTrue(Gate::forUser($user)->allows('deleteAny', ContactSegment::class));
    }

    public function test_manage_broadcast_can_do_everything(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageBroadcast->value);
        $segment = $this->segment();

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', ContactSegment::class));
        $this->assertTrue(Gate::forUser($user)->allows('view', $segment));
        $this->assertTrue(Gate::forUser($user)->allows('create', ContactSegment::class));
        $this->assertTrue(Gate::forUser($user)->allows('update', $segment));
        $this->assertTrue(Gate::forUser($user)->allows('delete', $segment));
        $this->assertTrue(Gate::forUser($user)->allows('deleteAny', ContactSegment::class));
    }

    private function segment(): ContactSegment
    {
        return ContactSegment::query()->create([
            'tenant_id' => '00000000-0000-0000-0000-000000000001',
            'name'      => 'VIP',
            'rules'     => ['match' => 'all', 'conditions' => []],
        ]);
    }
}
