<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Filament\Resources\Assistants\AssistantResource;
use App\Filament\Resources\Assistants\Pages\CreateAssistant;
use Database\Seeders\TenantAclSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\FeatureTestCase;

/**
 * Creating an assistant from the admin panel is governed only by
 * AssistantPolicy::create (ManageAssistants). A non-admin creator is attached to
 * the new assistant; an admin sees every assistant and is not attached.
 */
final class CreateAssistantAuthorizationTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        $this->app->make(TenantContextInterface::class)->set(
            new RuntimeTenant(id: self::TENANT_ID, schemaName: 'main'),
        );
    }

    public function test_non_admin_with_manage_assistants_creates_assistant_and_is_attached(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get($this->panelUrl('/admin/assistants/create'))->assertOk();

        Livewire::test(CreateAssistant::class)
            ->fillForm(['name' => 'Support Bot', 'default_language' => 'en'])
            ->call('create')
            ->assertHasNoFormErrors();

        $assistant = Assistant::query()->where('name', 'Support Bot')->firstOrFail();

        $this->assertTrue($user->assistants()->whereKey($assistant->getKey())->exists());
        $this->assertCount(1, $user->fresh()->assistants);
    }

    public function test_admin_creates_assistant_without_being_attached_and_still_sees_it(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleEnum::Admin->value);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreateAssistant::class)
            ->fillForm(['name' => 'Admin Bot', 'default_language' => 'en'])
            ->call('create')
            ->assertHasNoFormErrors();

        $assistant = Assistant::query()->where('name', 'Admin Bot')->firstOrFail();

        $this->assertCount(0, $admin->fresh()->assistants);
        $this->assertTrue(
            AssistantResource::getEloquentQuery()->whereKey($assistant->getKey())->exists(),
        );
    }

    public function test_user_without_manage_assistants_gets_403_on_create_page(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get($this->panelUrl('/admin/assistants/create'))->assertForbidden();
    }
}
