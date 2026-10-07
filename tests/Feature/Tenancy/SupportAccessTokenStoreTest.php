<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Tenancy\Models\SupportAccessToken;
use App\Domains\Tenancy\Services\SupportAccessTokenStore;
use Fapost\Foundation\Tenancy\DTO\SupportAccessRequest;
use Illuminate\Support\Carbon;
use Tests\Feature\FeatureTestCase;

/**
 * Redeeming a support access token: single use, short lived, bound to its tenant.
 */
final class SupportAccessTokenStoreTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    private const string OTHER_TENANT_ID = '00000000-0000-0000-0000-000000000002';

    public function test_a_fresh_token_is_redeemed_with_its_operator(): void
    {
        $token = $this->issue();

        $claim = $this->store()->redeem($token, self::TENANT_ID);

        $this->assertNotNull($claim);
        $this->assertSame('operator:7', $claim->operatorRef);
        $this->assertSame('Olga', $claim->operatorName);
        $this->assertSame('olga@example.com', $claim->operatorEmail);
        $this->assertNotNull(SupportAccessToken::query()->sole()->used_at);
    }

    public function test_a_token_cannot_be_replayed(): void
    {
        $token = $this->issue();

        $this->assertNotNull($this->store()->redeem($token, self::TENANT_ID));
        $this->assertNull($this->store()->redeem($token, self::TENANT_ID));
    }

    public function test_of_many_attempts_exactly_one_wins(): void
    {
        $token = $this->issue();

        $winners = 0;

        for ($attempt = 0; $attempt < 8; ++$attempt) {
            if (null !== $this->store()->redeem($token, self::TENANT_ID)) {
                ++$winners;
            }
        }

        $this->assertSame(1, $winners);
    }

    public function test_a_token_taken_by_someone_else_in_between_is_refused(): void
    {
        $token = $this->issue();

        // What a concurrent request's UPDATE leaves behind before ours runs.
        SupportAccessToken::query()->update(['used_at' => now()]);

        $this->assertNull($this->store()->redeem($token, self::TENANT_ID));
    }

    public function test_an_expired_token_is_refused(): void
    {
        $token = $this->issue();

        Carbon::setTestNow(now()->addSeconds(SupportAccessTokenStore::TTL_SECONDS + 1));

        try {
            $this->assertNull($this->store()->redeem($token, self::TENANT_ID));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_token_is_good_until_its_last_second(): void
    {
        Carbon::setTestNow(now());
        $token = $this->issue();

        Carbon::setTestNow(now()->addSeconds(SupportAccessTokenStore::TTL_SECONDS - 1));

        try {
            $this->assertNotNull($this->store()->redeem($token, self::TENANT_ID));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_token_of_another_tenant_is_refused_and_stays_usable_by_its_own(): void
    {
        $token = $this->issue();

        $this->assertNull($this->store()->redeem($token, self::OTHER_TENANT_ID));
        $this->assertNull(SupportAccessToken::query()->sole()->used_at);
        $this->assertNotNull($this->store()->redeem($token, self::TENANT_ID));
    }

    public function test_an_unknown_or_empty_token_is_refused(): void
    {
        $this->issue();

        $this->assertNull($this->store()->redeem('nope', self::TENANT_ID));
        $this->assertNull($this->store()->redeem('', self::TENANT_ID));
    }

    public function test_prune_removes_only_long_expired_rows(): void
    {
        $this->issue();
        $old = SupportAccessToken::query()->create([
            'tenant_id'      => self::TENANT_ID,
            'token_hash'     => hash('sha256', 'old'),
            'operator_ref'   => 'operator:1',
            'operator_name'  => 'Old',
            'operator_email' => 'old@example.com',
            'expires_at'     => now()->subDays(2),
            'created_at'     => now()->subDays(2),
        ]);

        $this->assertSame(1, $this->store()->prune());
        $this->assertNull(SupportAccessToken::query()->find($old->getKey()));
        $this->assertSame(1, SupportAccessToken::query()->count());
    }

    private function store(): SupportAccessTokenStore
    {
        return $this->app->make(SupportAccessTokenStore::class);
    }

    private function issue(): string
    {
        return $this->store()->issue(new SupportAccessRequest(self::TENANT_ID, 'operator:7', 'Olga', 'olga@example.com'))['token'];
    }
}
