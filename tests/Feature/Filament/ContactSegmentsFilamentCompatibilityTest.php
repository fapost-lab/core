<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\ContactGroup;
use App\Domains\Contact\Models\ContactSegment;
use App\Domains\Contact\Services\ContactSegmentService;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Filament\Assistant\Resources\ContactSegments\Pages\EditContactSegment;
use Database\Seeders\TenantAclSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTestCase;

/**
 * Filament stays the default UI next to the Inertia console, and both read the same rows: a segment saved by the
 * console's service, with a condition of every type, opens in Filament's edit form with every field filled.
 */
final class ContactSegmentsFilamentCompatibilityTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_filament_opens_a_segment_the_console_saved(): void
    {
        $this->seed(TenantAclSeeder::class);
        Filament::setCurrentPanel('assistant');

        $assistant = Assistant::factory()->create();
        $group     = ContactGroup::query()->create(['tenant_id' => self::TENANT_ID, 'name' => 'VIP']);
        $user      = User::factory()->create();
        $user->givePermissionTo([Permission::ManageAssistants->value, Permission::ManageContacts->value]);
        $user->assistants()->attach($assistant);

        app(TenantContextInterface::class)->set(Tenant::query()->firstOrFail());

        $segment = app(ContactSegmentService::class)->create('Saved by the console', [
            'match'      => 'any',
            'conditions' => [
                ['type' => 'tag', 'operator' => 'not_has', 'value' => [' spam ']],
                ['type' => 'language', 'operator' => 'in', 'value' => ['en', 'uk']],
                ['type' => 'platform', 'operator' => 'eq', 'value' => ['telegram']],
                ['type' => 'attribute', 'key' => 'profile.city', 'operator' => 'ne', 'value' => ['Kyiv']],
                ['type' => 'attribute', 'key' => 'age', 'operator' => 'exists', 'value' => ['ignored']],
                ['type' => 'group', 'operator' => 'not_in', 'value' => [(string) $group->getKey()]],
            ],
        ]);

        $this->actingAs($user);
        Filament::setTenant($assistant);

        $this->get(route('filament.assistant.resources.contact-segments.edit', ['tenant' => $assistant, 'record' => $segment->getKey()]))
            ->assertOk();

        $page = Livewire::test(EditContactSegment::class, ['record' => $segment->getKey()])->assertHasNoErrors();

        $data = $page->get('data');

        $this->assertSame('Saved by the console', $data['name']);
        $this->assertSame('any', $data['match']);
        $this->assertCount(6, $data['conditions']);

        $conditions = array_values($data['conditions']);

        $this->assertSame(['spam'], $conditions[0]['value']);
        $this->assertSame(['en', 'uk'], $conditions[1]['value']);
        $this->assertSame('profile.city', $conditions[3]['key']);
        $this->assertSame([(string) $group->getKey()], $conditions[5]['value_group']);
        $this->assertInstanceOf(ContactSegment::class, ContactSegment::query()->find($segment->getKey()));
    }
}
