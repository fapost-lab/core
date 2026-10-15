<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Str;

/**
 * `assistant` and `assistant/{tenant}`: no screens, but addresses Filament's assistant panel answered and a bookmark may
 * hold. Both send on to a dashboard.
 */
final class AssistantHomeConsoleTest extends InertiaConsoleTestCase
{
    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-0000000000ff';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_the_assistant_address_opens_the_first_assistant_the_user_may_open_by_name(): void
    {
        $beta  = Assistant::factory()->create(['name' => 'Beta']);
        $alpha = Assistant::factory()->create(['name' => 'Alpha']);
        Assistant::factory()->create(['name' => 'Aardvark', 'tenant_id' => self::OTHER_TENANT_ID]);

        $this->actingAs($this->admin())
            ->get($this->panelUrl('/assistant'))
            ->assertRedirect(route('filament.assistant.pages.dashboard', ['tenant' => (string) $alpha->getKey()]));

        $this->actingAs($this->manager($beta))
            ->get($this->panelUrl('/assistant'))
            ->assertRedirect(route('filament.assistant.pages.dashboard', ['tenant' => (string) $beta->getKey()]));
    }

    public function test_a_user_without_an_assistant_to_open_goes_to_the_admin_console(): void
    {
        Assistant::factory()->create();

        $this->actingAs($this->manager())
            ->get($this->panelUrl('/assistant'))
            ->assertRedirect(route('filament.admin.pages.dashboard'));
    }

    public function test_an_assistants_address_opens_its_dashboard(): void
    {
        $assistant = Assistant::factory()->create();

        $this->actingAs($this->manager($assistant))
            ->get($this->panelUrl("/assistant/{$assistant->getKey()}"))
            ->assertRedirect(route('filament.assistant.pages.dashboard', ['tenant' => (string) $assistant->getKey()]));
    }

    public function test_an_assistant_the_user_may_not_open_or_a_malformed_or_unknown_id_is_not_found(): void
    {
        $assistant = Assistant::factory()->create();

        $this->actingAs($this->manager())->get($this->panelUrl("/assistant/{$assistant->getKey()}"))->assertNotFound();
        $this->actingAs($this->admin())->get($this->panelUrl('/assistant/not-an-id'))->assertNotFound();
        $this->actingAs($this->admin())->get($this->panelUrl('/assistant/' . Str::lower((string) Str::ulid()->toRfc4122())))->assertNotFound();
    }

    public function test_the_sign_in_of_the_assistant_panel_is_not_taken_for_an_assistant(): void
    {
        $this->get($this->panelUrl('/assistant/login'))->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_a_guest_is_sent_to_the_sign_in(): void
    {
        $assistant = Assistant::factory()->create();

        $this->get($this->panelUrl('/assistant'))->assertRedirect(route('filament.admin.auth.login'));
        $this->get($this->panelUrl("/assistant/{$assistant->getKey()}"))->assertRedirect(route('filament.admin.auth.login'));
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    /**
     * A staff member who manages assistants, assigned to the given one.
     */
    private function manager(?Assistant $assistant = null): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageAssistants->value);

        if (null !== $assistant) {
            $user->assistants()->attach($assistant);
        }

        return $user;
    }
}
