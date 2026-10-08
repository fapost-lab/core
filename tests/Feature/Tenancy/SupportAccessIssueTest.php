<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Tenancy\Models\SupportAccessToken;
use Fapost\Foundation\Tenancy\Contracts\SupportAccessInterface;
use Fapost\Foundation\Tenancy\DTO\SupportAccessRequest;
use Fapost\Foundation\Tenancy\Enums\SupportAccessFailure;
use Fapost\Foundation\Tenancy\Exceptions\SupportAccessUnavailableException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\RunsInHostMode;
use Tests\Feature\FeatureTestCase;

/**
 * Issuing a support access grant through Foundation's contract: Core's implementation.
 */
final class SupportAccessIssueTest extends FeatureTestCase
{
    use RunsInHostMode;

    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreTenancyResolution();
    }

    public function test_it_is_off_by_default(): void
    {
        $this->assertFalse(config('tenancy.support_access.enabled'));

        $this->expectException(SupportAccessUnavailableException::class);
        $this->expectExceptionMessage('not enabled');

        $this->issuer()->issue($this->request());
    }

    public function test_it_issues_a_grant_for_an_active_tenant(): void
    {
        config(['tenancy.support_access.enabled' => true]);

        $grant = $this->issuer()->issue($this->request());

        $this->assertStringEndsWith('/support/enter', $grant->enterUrl);
        $this->assertSame(64, mb_strlen($grant->token()));
        $this->assertEqualsWithDelta(60, $grant->expiresAt->getTimestamp() - time(), 2);
        $this->assertSame('UTC', $grant->expiresAt->getTimezone()->getName());
    }

    public function test_only_the_hash_of_the_token_is_stored(): void
    {
        config(['tenancy.support_access.enabled' => true]);

        $grant = $this->issuer()->issue($this->request());

        $row = SupportAccessToken::query()->sole();
        $this->assertSame(hash('sha256', $grant->token()), $row->token_hash);
        $this->assertSame(self::TENANT_ID, $row->tenant_id);
        $this->assertSame('operator:7', $row->operator_ref);
        $this->assertSame('Olga', $row->operator_name);
        $this->assertSame('olga@example.com', $row->operator_email);
        $this->assertNull($row->used_at);
        $this->assertStringNotContainsString($grant->token(), json_encode(DB::connection('landlord')->table('support_access_tokens')->get()->all()));
    }

    public function test_the_grant_does_not_leak_the_token_through_dumps(): void
    {
        config(['tenancy.support_access.enabled' => true]);

        $grant = $this->issuer()->issue($this->request());

        $this->assertStringNotContainsString($grant->token(), print_r($grant, true));
    }

    public function test_it_is_host_mode_only(): void
    {
        config(['tenancy.support_access.enabled' => true, 'tenancy.resolution' => 'single']);

        try {
            $this->issuer()->issue($this->request());
            $this->fail('Expected SupportAccessUnavailableException.');
        } catch (SupportAccessUnavailableException $exception) {
            $this->assertSame(SupportAccessFailure::Disabled, $exception->reason);
        }
    }

    public function test_an_unknown_tenant_is_refused(): void
    {
        config(['tenancy.support_access.enabled' => true]);

        $this->expectException(SupportAccessUnavailableException::class);
        $this->expectExceptionMessage('was not found');

        $this->issuer()->issue($this->request('00000000-0000-0000-0000-0000000000ff'));
    }

    public function test_a_malformed_tenant_id_is_refused_as_unknown(): void
    {
        config(['tenancy.support_access.enabled' => true]);

        $this->expectException(SupportAccessUnavailableException::class);
        $this->expectExceptionMessage('was not found');

        $this->issuer()->issue($this->request('not-a-uuid'));
    }

    public function test_an_inactive_tenant_is_refused(): void
    {
        config(['tenancy.support_access.enabled' => true]);
        DB::connection('landlord')->table('tenants')->where('id', self::TENANT_ID)->update(['status' => 'suspended']);

        try {
            $this->issuer()->issue($this->request());
            $this->fail('Expected SupportAccessUnavailableException.');
        } catch (SupportAccessUnavailableException $exception) {
            $this->assertStringContainsString('not active', $exception->getMessage());
        }

        $this->assertSame(0, SupportAccessToken::query()->count());
    }

    private function issuer(): SupportAccessInterface
    {
        return $this->app->make(SupportAccessInterface::class);
    }

    private function request(string $tenantId = self::TENANT_ID): SupportAccessRequest
    {
        return new SupportAccessRequest($tenantId, 'operator:7', 'Olga', 'olga@example.com');
    }
}
