<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use App\Filament\Assistant\Resources\Broadcasts\BroadcastResource;
use App\Filament\Assistant\Resources\ContactSegments\ContactSegmentResource;
use Database\Seeders\TenantAclSeeder;
use Filament\Facades\Filament;
use Tests\Feature\FeatureTestCase;

/**
 * Broadcasts and segments are reachable only with the matching permission, not by any staff user.
 */
final class BroadcastAndSegmentAuthorizationTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
        Filament::setCurrentPanel('assistant');
    }

    public function test_broadcasts_are_denied_without_permission(): void
    {
        $assistant = Assistant::factory()->create();
        $user      = $this->userWithAssistantAccess($assistant);

        $this->actingAs($user);
        Filament::setTenant($assistant);

        $this->assertFalse(BroadcastResource::canAccess());
        $this->get(route('filament.assistant.resources.broadcasts.index', ['tenant' => $assistant]))->assertForbidden();
    }

    public function test_broadcasts_are_available_with_permission(): void
    {
        $assistant = Assistant::factory()->create();
        $user      = $this->userWithAssistantAccess($assistant);
        $user->givePermissionTo(Permission::ManageBroadcast->value);

        $this->actingAs($user);
        Filament::setTenant($assistant);

        $this->assertTrue(BroadcastResource::canAccess());
        $this->get(route('filament.assistant.resources.broadcasts.index', ['tenant' => $assistant]))->assertOk();
    }

    public function test_segments_are_denied_without_permission(): void
    {
        $assistant = Assistant::factory()->create();
        $user      = $this->userWithAssistantAccess($assistant);

        $this->actingAs($user);
        Filament::setTenant($assistant);

        $this->assertFalse(ContactSegmentResource::canAccess());
        $this->get(route('filament.assistant.resources.contact-segments.index', ['tenant' => $assistant]))->assertForbidden();
    }

    public function test_segments_are_available_with_manage_broadcast(): void
    {
        $assistant = Assistant::factory()->create();
        $user      = $this->userWithAssistantAccess($assistant);
        $user->givePermissionTo(Permission::ManageBroadcast->value);

        $this->actingAs($user);
        Filament::setTenant($assistant);

        $this->assertTrue(ContactSegmentResource::canAccess());
        $this->get(route('filament.assistant.resources.contact-segments.index', ['tenant' => $assistant]))->assertOk();
    }

    private function userWithAssistantAccess(Assistant $assistant): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $user->assistants()->attach($assistant);

        return $user;
    }
}
