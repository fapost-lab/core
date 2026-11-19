<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Credentials;

use App\Domains\Credentials\Models\Credential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CredentialModelTest extends CredentialsTestCase
{
    public function test_the_stored_secret_is_encrypted_and_reads_back_as_an_array(): void
    {
        $credential = $this->credential(['secret' => ['token' => self::SECRET, 'extra' => 'another-value']]);

        $raw = (string) DB::table('credentials')->where('id', $credential->id)->value('secret');

        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->assertStringNotContainsString('another-value', $raw);
        $this->assertSame(
            ['token' => self::SECRET, 'extra' => 'another-value'],
            Credential::query()->findOrFail($credential->id)->secret,
        );
    }

    public function test_the_secret_is_hidden_from_arrays_and_json(): void
    {
        $credential = $this->credential();

        $this->assertArrayNotHasKey('secret', $credential->toArray());
        $this->assertArrayNotHasKey('secret', Credential::query()->findOrFail($credential->id)->toArray());
        $this->assertStringNotContainsString(self::SECRET, $credential->toJson());
        $this->assertStringNotContainsString('"secret"', $credential->toJson());
    }

    public function test_the_key_is_a_ulid_based_uuid_and_json_columns_cast_to_arrays(): void
    {
        $credential = $this->credential(['settings' => ['header' => 'X-Key'], 'allowed_hosts' => ['*.example.com']]);
        $fresh      = Credential::query()->findOrFail($credential->id);

        $this->assertTrue(Str::isUuid($credential->id));
        $this->assertSame(['header' => 'X-Key'], $fresh->settings);
        $this->assertSame(['*.example.com'], $fresh->allowed_hosts);
        $this->assertNotNull($fresh->secret_changed_at);
    }

    public function test_a_name_is_unique_within_a_tenant_only(): void
    {
        $this->credential(['name' => 'CRM']);
        $this->credential(['name' => 'CRM'], '00000000-0000-0000-0000-0000000000ff');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->credential(['name' => 'CRM']);
    }
}
