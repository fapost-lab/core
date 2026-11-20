<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Credentials;

use App\Domains\Staff\Enums\Permission;
use App\Domains\Staff\Models\User;
use Database\Seeders\TenantAclSeeder;

/**
 * `GET /builder/credentials` feeds the call node's picker: references only, the tenant's own, for those who may edit
 * flow definitions.
 */
final class CredentialOptionsEndpointTest extends CredentialsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);
    }

    public function test_it_lists_the_references_of_the_tenant_without_any_value(): void
    {
        $crm = $this->credential(['name' => 'CRM', 'type' => 'api_key_header', 'settings' => ['header' => 'X-Secret-Header'], 'secret' => ['key' => self::SECRET]]);
        $this->credential(['name' => 'Billing']);
        $this->credential(['name' => 'Foreign'], '00000000-0000-0000-0000-0000000000ff');

        $response = $this->actingAs($this->flowEditor())->getJson('/builder/credentials')->assertOk();

        $response->assertExactJson(['data' => [
            ['id' => $this->idOf('Billing'), 'name' => 'Billing', 'type' => 'bearer'],
            ['id' => $crm->id, 'name' => 'CRM', 'type' => 'api_key_header'],
        ]]);
        $this->assertStringNotContainsString(self::SECRET, (string) $response->getContent());
        $this->assertStringNotContainsString('X-Secret-Header', (string) $response->getContent());
        $this->assertStringNotContainsString('api.example.com', (string) $response->getContent());
    }

    public function test_it_is_forbidden_without_the_flow_ability(): void
    {
        $this->credential();

        $this->actingAs(User::factory()->create())->getJson('/builder/credentials')->assertForbidden();

        // ManageCredentials alone (the console screen's right) does not open the builder's list.
        $manager = User::factory()->create();
        $manager->givePermissionTo(Permission::ManageCredentials->value);
        $this->actingAs($manager)->getJson('/builder/credentials')->assertForbidden();
    }

    public function test_it_is_forbidden_to_a_flow_editor_without_the_right_to_use_credentials(): void
    {
        $this->credential();

        $editor = User::factory()->create();
        $editor->givePermissionTo(Permission::ManageFlowDefinitions->value);

        $this->actingAs($editor)->getJson('/builder/credentials')->assertForbidden();
    }

    private function idOf(string $name): string
    {
        return (string) \App\Domains\Credentials\Models\Credential::query()->where('name', $name)->value('id');
    }

    private function flowEditor(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::ManageFlowDefinitions->value, Permission::UseCredentials->value);

        return $user;
    }
}
