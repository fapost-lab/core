<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Filament\Assistant\Resources\Channels\ChannelResource;
use Database\Seeders\TenantAclSeeder;
use Filament\Facades\Filament;

final class AssistantPanelTest extends FeatureTestCase
{
    public function test_admin_can_open_assistant_panel_dashboard(): void
    {
        $this->seedTenantAcl();

        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        $this->actingAs($user);

        $this->assertTrue(
            $user->canAccessPanel(Filament::getPanel('assistant')),
            'Fixture user must be allowed to access the assistant Filament panel.',
        );

        $url = $this->assistantPanelUrl($assistant);

        $this->get($url)->assertOk();
    }

    public function test_assigned_user_with_permission_can_open_assistant_panel(): void
    {
        $this->seedTenantAcl();

        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $user->assistants()->attach($assistant);

        $this->actingAs($user);

        $url = $this->assistantPanelUrl($assistant);

        $this->get($url)->assertOk();
    }

    public function test_non_assigned_user_cannot_access_assistant_panel(): void
    {
        $this->seedTenantAcl();

        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);

        $this->actingAs($user);

        $url = $this->assistantPanelUrl($assistant);

        $this->get($url)->assertNotFound();
    }

    public function test_assistant_tenant_root_redirects_to_dashboard(): void
    {
        $this->seedTenantAcl();

        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        $this->actingAs($user);

        $this->get(route('filament.assistant.home', ['tenant' => $assistant]))
            ->assertRedirect(route('filament.assistant.pages.dashboard', ['tenant' => $assistant]));
    }

    public function test_assistant_panel_resource_urls_include_tenant_segment(): void
    {
        $this->seedTenantAcl();

        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        $this->actingAs($user);

        Filament::setCurrentPanel('assistant');
        Filament::setTenant($assistant);

        $url = ChannelResource::getUrl('index');

        $this->assertStringContainsString($assistant->getKey(), $url);
        $this->assertStringNotContainsString('assistant=', $url);
    }

    public function test_assigned_user_with_translations_permission_can_open_assistant_translations(): void
    {
        $this->seedTenantAcl();

        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value, Permission::ManageTranslations->value);
        $user->assistants()->attach($assistant);

        $this->actingAs($user);

        $this->get($this->assistantTranslationsUrl($assistant))->assertOk();
    }

    /**
     * The translations page checks only the permission; the assistant it edits is guarded by the
     * panel's tenancy (`User::canAccessTenant()` → `AssistantPolicy::view`), so a user holding the
     * permission still cannot reach the translations of an assistant they are not assigned to.
     */
    public function test_translations_permission_does_not_open_an_unassigned_assistant(): void
    {
        $this->seedTenantAcl();

        $assigned   = Assistant::factory()->create();
        $unassigned = Assistant::factory()->create();
        $user       = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value, Permission::ManageTranslations->value);
        $user->assistants()->attach($assigned);

        $this->actingAs($user);

        $this->get($this->assistantTranslationsUrl($unassigned))->assertNotFound();
    }

    public function test_assigned_user_without_translations_permission_cannot_open_assistant_translations(): void
    {
        $this->seedTenantAcl();

        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $user->assistants()->attach($assistant);

        $this->actingAs($user);

        $this->get($this->assistantTranslationsUrl($assistant))->assertForbidden();
    }

    private function seedTenantAcl(): void
    {
        $this->seed(TenantAclSeeder::class);
    }

    private function assistantTranslationsUrl(Assistant $assistant): string
    {
        return route('filament.assistant.pages.translations', ['tenant' => $assistant]);
    }

    private function assistantPanelUrl(Assistant $assistant): string
    {
        return route('filament.assistant.pages.dashboard', ['tenant' => $assistant]);
    }
}
