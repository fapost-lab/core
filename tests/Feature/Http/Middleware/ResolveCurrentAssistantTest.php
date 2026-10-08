<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Http\Middleware\ResolveCurrentAssistant;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTestCase;
use Tests\Support\RecordingCurrentAssistant;

final class ResolveCurrentAssistantTest extends FeatureTestCase
{
    private RecordingCurrentAssistant $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);

        $this->recorder = new RecordingCurrentAssistant();
        $this->app->instance(CurrentAssistantInterface::class, $this->recorder);

        Route::middleware(['web', 'auth', 'tenant', ResolveCurrentAssistant::class])
            ->get('/_test/console/{assistant}', fn (): string => 'ok');
    }

    public function test_assigned_user_with_permission_gets_the_assistant_set(): void
    {
        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);
        $user->assistants()->attach($assistant);

        $this->actingAs($user)->get("/_test/console/{$assistant->getKey()}")->assertOk();

        $this->assertCount(1, $this->recorder->assistantsSet);
        $this->assertTrue($assistant->is($this->recorder->assistantsSet[0]));
    }

    public function test_unassigned_user_gets_404_and_nothing_is_set(): void
    {
        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);

        $this->actingAs($user)->get("/_test/console/{$assistant->getKey()}")->assertNotFound();

        $this->assertSame([], $this->recorder->assistantsSet);
    }

    public function test_nonexistent_assistant_gets_404(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        $this->actingAs($user)->get('/_test/console/' . Str::ulid())->assertNotFound();

        $this->assertSame([], $this->recorder->assistantsSet);
    }

    public function test_admin_gets_the_assistant_set(): void
    {
        $assistant = Assistant::factory()->create();
        $user      = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        $this->actingAs($user)->get("/_test/console/{$assistant->getKey()}")->assertOk();

        $this->assertTrue($assistant->is($this->recorder->assistantsSet[0]));
    }
}
