<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Contact;

use App\Domains\Contact\Models\Contact;
use App\Domains\Contact\Models\ContactGroup;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

final class ContactGroupTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_attach_and_detach_toggles_membership_both_ways(): void
    {
        $contact = $this->contact();
        $group   = $this->group();

        $this->assertSame(0, $contact->groups()->count());
        $this->assertSame(0, $group->contacts()->count());

        $contact->groups()->attach($group);

        $this->assertSame(1, $contact->groups()->count());
        $this->assertTrue($contact->groups()->whereKey($group->getKey())->exists());
        $this->assertSame(1, $group->contacts()->count());
        $this->assertTrue($group->contacts()->whereKey($contact->getKey())->exists());

        $contact->groups()->detach($group);

        $this->assertSame(0, $contact->groups()->count());
        $this->assertSame(0, $group->contacts()->count());
    }

    public function test_repeated_sync_does_not_duplicate_pivot_row(): void
    {
        $contact = $this->contact();
        $group   = $this->group();

        $contact->groups()->sync([$group->getKey()]);
        $contact->groups()->sync([$group->getKey()]);

        $this->assertSame(
            1,
            DB::table('contact_group_members')
                ->where('contact_id', $contact->getKey())
                ->where('contact_group_id', $group->getKey())
                ->count(),
        );
    }

    public function test_contact_can_belong_to_multiple_groups_simultaneously(): void
    {
        $contact = $this->contact();
        $groupA  = $this->group('Group A');
        $groupB  = $this->group('Group B');

        $contact->groups()->attach([$groupA->getKey(), $groupB->getKey()]);

        $this->assertSame(2, $contact->groups()->count());
        $this->assertTrue($contact->groups()->whereKey($groupA->getKey())->exists());
        $this->assertTrue($contact->groups()->whereKey($groupB->getKey())->exists());
    }

    private function contact(): Contact
    {
        return Contact::factory()->forTenant(self::TENANT_ID)->create();
    }

    private function group(string $name = 'Group'): ContactGroup
    {
        return ContactGroup::query()->create([
            'tenant_id' => self::TENANT_ID,
            'name'      => $name,
        ]);
    }
}
