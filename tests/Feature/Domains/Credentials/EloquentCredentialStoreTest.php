<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Credentials;

use App\Domains\Credentials\EloquentCredentialStore;
use App\Domains\Credentials\Models\Credential;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Credentials\Contracts\CredentialStoreInterface;
use Fapost\Foundation\Credentials\Exceptions\CredentialNotFoundException;
use Fapost\Foundation\Credentials\Exceptions\CredentialUnavailableException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class EloquentCredentialStoreTest extends CredentialsTestCase
{
    private const string FOREIGN_TENANT = '00000000-0000-0000-0000-0000000000ff';

    public function test_the_secret_carries_the_values_settings_hosts_and_the_tenant(): void
    {
        $credential = $this->credential([
            'type'          => 'api_key_header',
            'settings'      => ['header' => 'X-Key'],
            'allowed_hosts' => ['api.example.com', '*.example.org'],
            'secret'        => ['key' => self::SECRET],
        ]);

        $secret = $this->store()->secret($credential->id);

        $this->assertSame($this->tenantId, $secret->tenantId);
        $this->assertSame($credential->id, $secret->id);
        $this->assertSame('api_key_header', $secret->type);
        $this->assertSame(['header' => 'X-Key'], $secret->settings);
        $this->assertSame(['api.example.com', '*.example.org'], $secret->allowedHosts);
        $this->assertSame(self::SECRET, $secret->value('key'));
        $this->assertSame('X-Key', $secret->setting('header'));
    }

    public function test_a_credential_of_another_tenant_a_malformed_id_and_an_unknown_id_are_not_found(): void
    {
        $foreign = $this->credential([], self::FOREIGN_TENANT);

        foreach ([$foreign->id, 'not-a-uuid', (string) Str::uuid(), '', "1' OR '1'='1"] as $id) {
            try {
                $this->store()->secret($id);
                $this->fail("Credential id [{$id}] must not be found.");
            } catch (CredentialNotFoundException $exception) {
                $this->assertSame($id, $exception->credentialId);
            }
        }
    }

    public function test_the_version_changes_after_an_update_even_within_the_same_second(): void
    {
        $credential = $this->credential();
        $before     = $this->store()->secret($credential->id)->version;

        $this->assertSame($before, $this->store()->secret($credential->id)->version);

        $credential->update(['secret' => ['token' => 'a-different-secret']]);

        $this->assertNotSame($before, $this->store()->secret($credential->id)->version);
    }

    public function test_references_list_the_tenants_credentials_by_name_without_values(): void
    {
        $this->credential(['name' => 'Zeta', 'type' => 'bearer']);
        $this->credential(['name' => 'Alpha', 'type' => 'basic', 'settings' => ['username' => 'u'], 'secret' => ['password' => self::SECRET]]);
        $this->credential(['name' => 'Foreign'], self::FOREIGN_TENANT);

        $references = $this->store()->references();

        $this->assertSame(['Alpha', 'Zeta'], array_map(static fn ($reference): string => $reference->name, $references));
        $this->assertStringNotContainsString(self::SECRET, serialize($references));
        $this->assertSame(['Alpha'], array_map(static fn ($reference): string => $reference->name, $this->store()->references('basic')));
        $this->assertSame([], $this->store()->references('oauth2_client_credentials'));
    }

    public function test_it_fails_fast_without_a_tenant(): void
    {
        $this->app->make(TenantContextInterface::class)->reset();

        $this->expectException(RuntimeException::class);

        $this->store()->references();
    }

    public function test_a_secret_that_cannot_be_decrypted_is_unavailable_not_a_crash(): void
    {
        $credential = $this->credential();
        DB::table('credentials')->where('id', $credential->id)->update(['secret' => 'garbage']);

        $this->expectException(CredentialUnavailableException::class);

        $this->store()->secret($credential->id);
    }

    public function test_the_binding_is_scoped_and_reads_the_current_tenant_of_each_scope(): void
    {
        $first = $this->app->make(CredentialStoreInterface::class);

        $this->assertInstanceOf(EloquentCredentialStore::class, $first);
        $this->assertSame($first, $this->app->make(CredentialStoreInterface::class));

        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, $this->app->make(CredentialStoreInterface::class));
    }

    public function test_the_credential_count_is_not_affected_by_reads(): void
    {
        $this->credential();
        $this->store()->references();

        $this->assertSame(1, Credential::query()->count());
    }

    private function store(): EloquentCredentialStore
    {
        return $this->app->make(EloquentCredentialStore::class);
    }
}
