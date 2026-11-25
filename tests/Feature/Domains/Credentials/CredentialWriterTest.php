<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Credentials;

use App\Domains\Audit\Models\ActivityEntry;
use App\Domains\Credentials\CredentialWriter;
use App\Domains\Credentials\Models\Credential;
use App\Domains\Credentials\Models\CredentialOAuthGrant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CredentialWriterTest extends CredentialsTestCase
{
    public function test_it_creates_a_credential_with_an_encrypted_secret_and_normalized_hosts(): void
    {
        $credential = $this->writer()->create([
            'name'          => '  CRM  ',
            'type'          => 'api_key_header',
            'settings'      => ['header' => 'X-Api-Key', 'prefix' => '', 'unknown' => 'dropped'],
            'secrets'       => ['key' => self::SECRET, 'unknown' => 'dropped-secret'],
            'allowed_hosts' => ['API.Example.com', 'api.example.com', '*.Example.org'],
        ]);

        $fresh = Credential::query()->findOrFail($credential->id);

        $this->assertSame($this->tenantId, $fresh->tenant_id);
        $this->assertSame('CRM', $fresh->name);
        $this->assertSame('api_key_header', $fresh->type);
        $this->assertSame(['header' => 'X-Api-Key'], $fresh->settings);
        $this->assertSame(['api.example.com', '*.example.org'], $fresh->allowed_hosts);
        $this->assertSame(['key' => self::SECRET], $fresh->secret);
        $this->assertStringNotContainsString(self::SECRET, (string) DB::table('credentials')->value('secret'));
        $this->assertTrue($fresh->secret_changed_at->isToday());
    }

    public function test_find_is_bounded_by_the_tenant_and_the_id_shape(): void
    {
        $own     = $this->credential();
        $foreign = $this->credential([], '00000000-0000-0000-0000-0000000000ff');

        $this->assertSame($own->id, $this->writer()->find($own->id)->id);

        foreach ([$foreign->id, 'nonsense', (string) Str::uuid()] as $id) {
            try {
                $this->writer()->find($id);
                $this->fail("Credential id [{$id}] must not be found.");
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame([$own->id], $this->writer()->query()->pluck('id')->all());
    }

    public function test_validation_errors_carry_no_value(): void
    {
        $secret = 'tiny';

        try {
            $this->writer()->create([
                'name'          => 'CRM',
                'type'          => 'oauth2_client_credentials',
                'settings'      => ['token_url' => 'http://' . self::SECRET . '.example.com/token', 'client_id' => 'id-1', 'client_auth' => self::SECRET],
                'secrets'       => ['client_secret' => $secret],
                'allowed_hosts' => ['https://' . self::SECRET . '.example.com', 'ok.example.com'],
            ]);
            $this->fail('The input is invalid.');
        } catch (ValidationException $exception) {
            $keys = array_keys($exception->errors());
            sort($keys);

            $this->assertSame(['allowed_hosts.0', 'secrets.client_secret', 'settings.client_auth', 'settings.token_url'], $keys);

            $everything = json_encode([$exception->errors(), $exception->getMessage()], JSON_THROW_ON_ERROR);

            foreach ([self::SECRET, $secret, 'client_secret' . ': '] as $value) {
                $this->assertStringNotContainsString($value, $everything);
            }
        }

        $this->assertSame(0, Credential::query()->count());
    }

    public function test_the_required_fields_the_choices_and_the_url_rules(): void
    {
        $base = [
            'name'          => 'Auth',
            'type'          => 'oauth2_client_credentials',
            'allowed_hosts' => ['auth.example.com'],
        ];

        $this->assertErrors(['settings.token_url', 'settings.client_id', 'settings.client_auth', 'secrets.client_secret'], $base);

        $good = $base + [
            'settings' => ['token_url' => 'https://auth.example.com/token', 'client_id' => 'c', 'client_auth' => 'body'],
            'secrets'  => ['client_secret' => self::SECRET],
        ];

        $this->assertSame('oauth2_client_credentials', $this->writer()->create($good)->type);

        foreach ([
            'http://auth.example.com/token',
            'https://user:pw@auth.example.com/token',
            'https://other.example.com/token',
            'auth.example.com/token',
            'ftp://auth.example.com/token',
        ] as $url) {
            $bad                          = $good;
            $bad['name']                  = 'Auth ' . $url;
            $bad['settings']['token_url'] = $url;

            $this->assertErrors(['settings.token_url'], $bad);
        }

        $bad                            = $good;
        $bad['name']                    = 'Auth select';
        $bad['settings']['client_auth'] = 'header';
        $this->assertErrors(['settings.client_auth'], $bad);
    }

    public function test_a_wildcard_host_admits_the_token_url_of_a_subdomain(): void
    {
        $credential = $this->writer()->create([
            'name'          => 'Auth',
            'type'          => 'oauth2_client_credentials',
            'settings'      => ['token_url' => 'https://login.eu.example.com/token', 'client_id' => 'c', 'client_auth' => 'basic'],
            'secrets'       => ['client_secret' => self::SECRET],
            'allowed_hosts' => ['*.example.com'],
        ]);

        $this->assertSame('basic', $credential->settings['client_auth']);
    }

    public function test_the_type_the_name_the_hosts_and_the_secret_are_checked(): void
    {
        $ok = ['name' => 'Bearer', 'type' => 'bearer', 'secrets' => ['token' => self::SECRET], 'allowed_hosts' => ['api.example.com']];

        $this->assertErrors(['type'], [...$ok, 'type' => 'nope']);
        $this->assertErrors(['type'], [...$ok, 'type' => null]);
        $this->assertErrors(['name'], [...$ok, 'name' => '   ']);
        $this->assertErrors(['name'], [...$ok, 'name' => str_repeat('a', 121)]);
        $this->assertErrors(['allowed_hosts'], [...$ok, 'allowed_hosts' => []]);
        $this->assertErrors(['allowed_hosts'], [...$ok, 'allowed_hosts' => array_map(static fn (int $i): string => "h{$i}.example.com", range(1, 21))]);
        $this->assertErrors(['allowed_hosts.1', 'allowed_hosts.2'], [...$ok, 'allowed_hosts' => ['a.example.com', '*', 'x:80']]);
        $this->assertErrors(['secrets.token'], [...$ok, 'secrets' => ['token' => '1234567']]);
        $this->assertErrors(['secrets.token'], [...$ok, 'secrets' => []]);
        $this->assertErrors(['secrets.token'], [...$ok, 'secrets' => ['token' => ['x']]]);

        $this->writer()->create([...$ok, 'allowed_hosts' => array_map(static fn (int $i): string => "h{$i}.example.com", range(1, 20))]);
        $this->assertSame(1, Credential::query()->count());
    }

    public function test_a_name_is_unique_within_the_tenant(): void
    {
        $this->credential(['name' => 'CRM']);
        $this->credential(['name' => 'CRM'], '00000000-0000-0000-0000-0000000000ff');

        $input = ['name' => 'CRM', 'type' => 'bearer', 'secrets' => ['token' => self::SECRET], 'allowed_hosts' => ['api.example.com']];

        $this->assertErrors(['name'], $input);

        $other = $this->credential(['name' => 'Other']);

        try {
            $this->writer()->update($other, ['name' => 'CRM', 'allowed_hosts' => ['api.example.com']]);
            $this->fail('A name of another credential must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(['name'], array_keys($exception->errors()));
        }

        // Keeping its own name is not a clash.
        $this->assertSame('Other', $this->writer()->update($other, ['name' => 'Other', 'allowed_hosts' => ['api.example.com']])->name);
    }

    public function test_an_update_keeps_the_stored_secret_when_none_is_given_and_never_changes_the_type(): void
    {
        $credential = $this->credential(['name' => 'CRM']);
        $changedAt  = $credential->secret_changed_at;
        Carbon::setTestNow(Carbon::now()->addHour());

        $updated = $this->writer()->update($credential, [
            'name'          => 'CRM 2',
            'type'          => 'basic',
            'settings'      => ['username' => 'ignored'],
            'secrets'       => ['token' => ''],
            'allowed_hosts' => ['API.example.com.'],
        ]);

        $fresh = Credential::query()->findOrFail($credential->id);

        $this->assertSame($updated->id, $fresh->id);
        $this->assertSame('bearer', $fresh->type);
        $this->assertSame('CRM 2', $fresh->name);
        $this->assertSame(['token' => self::SECRET], $fresh->secret);
        $this->assertSame([], $fresh->settings);
        $this->assertTrue($fresh->secret_changed_at->equalTo($changedAt));
        $this->assertSame(['api.example.com'], $fresh->allowed_hosts);
    }

    public function test_replacing_the_secret_moves_secret_changed_at(): void
    {
        $credential = $this->credential();
        $changedAt  = $credential->secret_changed_at;
        Carbon::setTestNow(Carbon::now()->addHour());

        $this->writer()->update($credential, ['name' => $credential->name, 'secrets' => ['token' => 'a-new-secret-value'], 'allowed_hosts' => ['api.example.com']]);

        $fresh = Credential::query()->findOrFail($credential->id);

        $this->assertSame(['token' => 'a-new-secret-value'], $fresh->secret);
        $this->assertTrue($fresh->secret_changed_at->greaterThan($changedAt));
    }

    public function test_an_update_with_a_secret_that_does_not_decrypt_lets_the_admin_enter_it_again(): void
    {
        $credential = $this->credential();
        DB::table('credentials')->where('id', $credential->id)->update(['secret' => 'garbage']);

        $this->writer()->update(Credential::query()->findOrFail($credential->id), [
            'name'          => $credential->name,
            'secrets'       => ['token' => 'a-new-secret-value'],
            'allowed_hosts' => ['api.example.com'],
        ]);

        $this->assertSame(['token' => 'a-new-secret-value'], Credential::query()->findOrFail($credential->id)->secret);
    }

    public function test_an_update_that_empties_a_required_secret_nobody_holds_is_refused(): void
    {
        $credential = $this->credential();
        DB::table('credentials')->where('id', $credential->id)->update(['secret' => 'garbage']);

        $this->expectException(ValidationException::class);

        $this->writer()->update(Credential::query()->findOrFail($credential->id), ['name' => $credential->name, 'allowed_hosts' => ['api.example.com']]);
    }

    public function test_delete_removes_the_credential_and_its_cached_tokens(): void
    {
        $credential                                                                       = $this->credential();
        $other                                                                            = $this->credential();
        $this->redis->values["credentials:oauth2:{$this->tenantId}:{$credential->id}:v1"] = 'x';
        $this->redis->values["credentials:oauth2:{$this->tenantId}:{$other->id}:v1"]      = 'y';

        $this->writer()->delete($credential);

        $this->assertNull(Credential::query()->find($credential->id));
        $this->assertSame(["credentials:oauth2:{$this->tenantId}:{$other->id}:v1"], array_keys($this->redis->values));
    }

    public function test_an_update_drops_the_cached_tokens_of_the_credential(): void
    {
        $credential                                                                       = $this->credential();
        $this->redis->values["credentials:oauth2:{$this->tenantId}:{$credential->id}:v1"] = 'x';

        $this->writer()->update($credential, ['name' => 'Renamed', 'allowed_hosts' => ['api.example.com']]);

        $this->assertSame([], $this->redis->values);
    }

    public function test_the_audit_entries_name_the_action_without_any_value(): void
    {
        $credential = $this->writer()->create([
            'name'          => 'CRM',
            'type'          => 'api_key_header',
            'settings'      => ['header' => 'X-Secret-Header', 'prefix' => 'Prefix-Value '],
            'secrets'       => ['key' => self::SECRET],
            'allowed_hosts' => ['api.example.com'],
        ]);

        $this->writer()->update($credential, [
            'name'          => 'CRM renamed',
            'settings'      => ['header' => 'X-Other-Header'],
            'secrets'       => ['key' => 'replacement-secret-value'],
            'allowed_hosts' => ['api.example.com', 'b.example.com'],
        ]);

        $this->writer()->update($credential->fresh() ?? $credential, [
            'name'          => 'CRM renamed',
            'settings'      => ['header' => 'X-Other-Header'],
            'allowed_hosts' => ['api.example.com', 'b.example.com'],
        ]);

        $this->writer()->delete($credential->fresh() ?? $credential);

        $entries = ActivityEntry::query()->orderBy('id')->get();

        $this->assertSame(
            ['credential.created', 'credential.updated', 'credential.deleted'],
            $entries->pluck('description')->all(),
            'An update that changes nothing records nothing.',
        );
        [$created, $updated, $deleted] = $entries->all();

        $this->assertSame('settings', $entries[0]->log_name);
        $this->assertSame(['name' => 'CRM', 'type' => 'api_key_header', 'allowed_hosts' => ['api.example.com']], $created->attribute_changes?->toArray()['attributes']);
        $this->assertSame('CRM renamed', $updated->attribute_changes?->toArray()['attributes']['name']);
        $this->assertSame(['settings.header', 'settings.prefix'], $updated->attribute_changes?->toArray()['changed']);
        $this->assertEquals(['secret_replaced' => true, 'hosts_widened' => true], $updated->properties?->toArray());
        $this->assertSame('CRM renamed', $deleted->subject_label);

        $dump = json_encode($entries->map(static fn (ActivityEntry $entry): array => $entry->getAttributes())->all(), JSON_THROW_ON_ERROR);

        foreach ([self::SECRET, 'replacement-secret-value', 'X-Secret-Header', 'X-Other-Header', 'Prefix-Value'] as $value) {
            $this->assertStringNotContainsString($value, $dump);
        }
    }

    public function test_widening_the_hosts_without_entering_the_secret_again_is_refused(): void
    {
        $credential = $this->credential(['name' => 'CRM']);

        try {
            $this->writer()->update($credential, ['name' => 'CRM', 'allowed_hosts' => ['api.example.com', 'collector.attacker.test']]);
            $this->fail('A stored secret must not reach a new host.');
        } catch (ValidationException $exception) {
            $this->assertSame(['secrets.token'], array_keys($exception->errors()));
            $this->assertSame([__('credential_validation.secret_reenter_for_new_host')], $exception->errors()['secrets.token']);
        }

        $fresh = Credential::query()->findOrFail($credential->id);

        $this->assertSame(['api.example.com'], $fresh->allowed_hosts);
        $this->assertSame(0, ActivityEntry::query()->count());
    }

    public function test_widening_the_hosts_with_the_secret_entered_again_is_saved_and_audited(): void
    {
        $credential = $this->credential(['name' => 'CRM']);

        $this->writer()->update($credential, [
            'name'          => 'CRM',
            'secrets'       => ['token' => 'the-same-or-new-value'],
            'allowed_hosts' => ['api.example.com', 'b.example.com'],
        ]);

        $fresh = Credential::query()->findOrFail($credential->id);

        $this->assertSame(['api.example.com', 'b.example.com'], $fresh->allowed_hosts);
        $this->assertSame(['token' => 'the-same-or-new-value'], $fresh->secret);
        $this->assertEquals(['secret_replaced' => true, 'hosts_widened' => true], ActivityEntry::query()->sole()->properties?->toArray());
    }

    public function test_narrowing_the_hosts_keeps_the_stored_secret(): void
    {
        $credential = $this->credential(['allowed_hosts' => ['api.example.com', 'b.example.com', '*.vendor.test']]);

        $this->writer()->update($credential, ['name' => $credential->name, 'allowed_hosts' => ['api.vendor.test', '*.eu.vendor.test']]);

        $fresh = Credential::query()->findOrFail($credential->id);

        $this->assertSame(['api.vendor.test', '*.eu.vendor.test'], $fresh->allowed_hosts);
        $this->assertSame(['token' => self::SECRET], $fresh->secret);
        $this->assertSame(['secret_replaced' => false], ActivityEntry::query()->sole()->properties?->toArray());
    }

    public function test_a_wildcard_over_a_listed_host_widens_the_list(): void
    {
        $credential = $this->credential(['allowed_hosts' => ['api.example.com']]);

        $this->expectException(ValidationException::class);

        $this->writer()->update($credential, ['name' => $credential->name, 'allowed_hosts' => ['*.example.com']]);
    }

    public function test_moving_the_token_url_to_another_host_asks_for_the_secret_again(): void
    {
        $credential = $this->credential([
            'type'          => 'oauth2_client_credentials',
            'allowed_hosts' => ['*.example.com'],
            'settings'      => ['token_url' => 'https://auth.example.com/token', 'client_id' => 'c', 'client_auth' => 'basic'],
            'secret'        => ['client_secret' => self::SECRET],
        ]);
        $input = [
            'name'          => $credential->name,
            'settings'      => ['token_url' => 'https://other.example.com/token', 'client_id' => 'c', 'client_auth' => 'basic'],
            'allowed_hosts' => ['*.example.com'],
        ];

        try {
            $this->writer()->update($credential, $input);
            $this->fail('The client secret must not follow the token URL to another host unasked.');
        } catch (ValidationException $exception) {
            $this->assertSame(['secrets.client_secret'], array_keys($exception->errors()));
        }

        // The same host, another path: nothing to re-enter.
        $this->writer()->update($credential, [...$input, 'settings' => [...$input['settings'], 'token_url' => 'https://auth.example.com/v2/token']]);

        $this->assertSame('https://auth.example.com/v2/token', Credential::query()->findOrFail($credential->id)->settings['token_url']);
    }

    public function test_widening_the_hosts_of_a_consent_credential_drops_its_grant(): void
    {
        $credential = $this->oauthCredential();
        $this->grant($credential);

        $this->writer()->update($credential, [
            'name'          => $credential->name,
            'settings'      => $credential->settings,
            'secrets'       => ['client_secret' => self::SECRET],
            'allowed_hosts' => ['api.example.com', 'auth.example.com', 'api2.example.com'],
        ]);

        $this->assertSame(0, CredentialOAuthGrant::query()->count());
        // Compared as a map: a jsonb column (Postgres) does not keep the key order.
        $this->assertEquals(
            ['secret_replaced' => false, 'hosts_widened' => true, 'grant_dropped' => true],
            ActivityEntry::query()->sole()->properties?->toArray(),
        );
    }

    public function test_narrowing_the_hosts_of_a_consent_credential_keeps_its_grant(): void
    {
        $credential = $this->oauthCredential();
        $this->grant($credential);

        $this->writer()->update($credential, [
            'name'          => $credential->name,
            'settings'      => $credential->settings,
            'allowed_hosts' => ['auth.example.com'],
        ]);

        $this->assertSame(1, CredentialOAuthGrant::query()->count());
    }

    public function test_a_credential_of_an_unregistered_type_cannot_be_updated(): void
    {
        $credential = $this->credential(['type' => 'gone_type', 'settings' => ['header' => 'X-Key']]);

        try {
            $this->writer()->update($credential, ['name' => 'Renamed', 'allowed_hosts' => ['api.example.com']]);
            $this->fail('An unknown type must be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame(['type'], array_keys($exception->errors()));
        }

        $fresh = Credential::query()->findOrFail($credential->id);

        $this->assertSame($credential->name, $fresh->name);
        $this->assertSame(['header' => 'X-Key'], $fresh->settings);
        $this->assertSame(['token' => self::SECRET], $fresh->secret);
        $this->assertSame(0, ActivityEntry::query()->count());
    }

    public function test_an_update_without_a_secret_records_that_it_was_not_replaced(): void
    {
        $credential = $this->credential(['name' => 'CRM']);

        $this->writer()->update($credential, ['name' => 'CRM 2', 'allowed_hosts' => ['api.example.com']]);

        $entry = ActivityEntry::query()->sole();

        $this->assertSame(['secret_replaced' => false], $entry->properties?->toArray());
    }

    public function test_a_failed_audit_entry_rolls_the_write_back(): void
    {
        config(['activitylog.enabled' => false]);

        try {
            $this->writer()->create(['name' => 'CRM', 'type' => 'bearer', 'secrets' => ['token' => self::SECRET], 'allowed_hosts' => ['api.example.com']]);
            $this->fail('The entry could not be written.');
        } catch (Throwable) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, Credential::query()->count());
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $input
     */
    private function assertErrors(array $keys, array $input): void
    {
        $count = Credential::query()->count();

        try {
            $this->writer()->create($input);
            $this->fail('Expected errors for ' . implode(', ', $keys));
        } catch (ValidationException $exception) {
            $this->assertSame($keys, array_keys($exception->errors()));
        }

        $this->assertSame($count, Credential::query()->count());
    }

    private function writer(): CredentialWriter
    {
        return $this->app->make(CredentialWriter::class);
    }
}
