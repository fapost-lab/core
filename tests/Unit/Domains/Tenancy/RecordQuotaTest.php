<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Services\LimitRegistry;
use App\Domains\Tenancy\Services\RecordQuota;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\UnlimitedTenantLimits;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Quota\Contracts\RecordQuotaInterface;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Fapost\Foundation\Quota\DTO\LimitDefinition;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Foundation\Application;
use LogicException;
use Tests\Support\FakeTenantLimits;
use Tests\TestCase;

final class RecordQuotaTest extends TestCase
{
    private const string TENANT_ID = '01k00000000000000000000000';

    public function test_without_a_limit_everything_is_allowed(): void
    {
        $quota = $this->quota(new UnlimitedTenantLimits());

        $this->assertTrue($quota->canCreate('assistants', 1_000_000));
        $quota->assertCanCreate('assistants', 1_000_000);
    }

    public function test_allows_below_the_limit_and_refuses_at_it(): void
    {
        $quota = $this->quota(new FakeTenantLimits(['assistants' => 1]));

        $this->assertTrue($quota->canCreate('assistants', 0));
        $this->assertFalse($quota->canCreate('assistants', 1));
    }

    public function test_assert_throws_a_human_message(): void
    {
        $quota = $this->quota(new FakeTenantLimits(['assistants' => 1]));

        try {
            $quota->assertCanCreate('assistants', 1);
            $this->fail('Expected RecordLimitReachedException.');
        } catch (RecordLimitReachedException $e) {
            $this->assertSame('Assistants limit reached: 1 of 1.', $e->getMessage());
            $this->assertSame('assistants', $e->key);
            $this->assertSame(1, $e->limit);
            $this->assertSame(1, $e->current);
        }
    }

    public function test_limit_zero_refuses_the_first_record(): void
    {
        $quota = $this->quota(new FakeTenantLimits(['assistants' => 0]));

        $this->assertFalse($quota->canCreate('assistants', 0));
    }

    public function test_over_the_limit_after_a_downgrade_refuses_creation(): void
    {
        $quota = $this->quota(new FakeTenantLimits(['assistants' => 1]));

        $this->assertFalse($quota->canCreate('assistants', 3));
    }

    public function test_unknown_key_is_a_logic_exception_even_without_limits(): void
    {
        $quota = $this->quota(new UnlimitedTenantLimits());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('not registered');

        $quota->canCreate('asistants', 0);
    }

    public function test_unknown_key_throws_on_assert_too(): void
    {
        $this->expectException(LogicException::class);

        $this->quota(new UnlimitedTenantLimits())->assertCanCreate('asistants', 0);
    }

    public function test_record_quota_interface_resolves_to_core_implementation(): void
    {
        $this->assertInstanceOf(RecordQuota::class, $this->app->make(RecordQuotaInterface::class));
    }

    public function test_default_binding_is_unlimited(): void
    {
        $app = new Application(base_path());
        (new \App\Providers\DomainServiceProvider($app))->register();

        $limits = $app->make(TenantLimitsInterface::class);

        $this->assertInstanceOf(UnlimitedTenantLimits::class, $limits);
        $this->assertNull($limits->limitFor(self::TENANT_ID, 'assistants'));
    }

    public function test_a_binding_made_before_core_is_not_overwritten(): void
    {
        $fake = new FakeTenantLimits(['assistants' => 5]);
        $app  = new Application(base_path());
        $app->singleton(TenantLimitsInterface::class, static fn (): TenantLimitsInterface => $fake);

        (new \App\Providers\DomainServiceProvider($app))->register();

        $this->assertSame($fake, $app->make(TenantLimitsInterface::class));
    }

    private function quota(TenantLimitsInterface $limits): RecordQuota
    {
        $registry = new LimitRegistry();
        $registry->register(new LimitDefinition('assistants', 'Assistants', 'assistants', LimitKind::Records));

        $context = new TenantContext();
        $context->set($this->tenant());

        return new RecordQuota($registry, $limits, $context);
    }

    private function tenant(): RuntimeTenant
    {
        return new RuntimeTenant(self::TENANT_ID, 'main');
    }
}
