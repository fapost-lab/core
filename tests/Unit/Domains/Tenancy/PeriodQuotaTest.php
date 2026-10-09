<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Services\LimitRegistry;
use App\Domains\Tenancy\Services\PeriodQuota;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\UnlimitedUsageMeter;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Providers\DomainServiceProvider;
use DateTimeImmutable;
use Fapost\Foundation\Quota\Contracts\UsageMeterInterface;
use Fapost\Foundation\Quota\DTO\LimitDefinition;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use LogicException;
use RuntimeException;
use Tests\Support\FakeUsageMeter;
use Tests\TestCase;

final class PeriodQuotaTest extends TestCase
{
    private const string TENANT_ID = '01k00000000000000000000000';

    public function test_it_passes_the_unit_with_the_tenant_from_the_context(): void
    {
        $meter = FakeUsageMeter::allowing();
        $at    = new DateTimeImmutable('2026-10-10 12:00:00');

        $decision = $this->quota($meter)->consume('visits', 'contact:abc', $at);

        $this->assertTrue($decision->allowed);
        $this->assertCount(1, $meter->units);
        $this->assertSame(self::TENANT_ID, $meter->units[0]->tenantId);
        $this->assertSame('visits', $meter->units[0]->key);
        $this->assertSame('contact:abc', $meter->units[0]->unitKey);
        $this->assertSame($at, $meter->units[0]->occurredAt);
    }

    public function test_it_returns_the_operators_refusal(): void
    {
        $decision = $this->quota(FakeUsageMeter::denying(5, 5))->consume('visits', 'u', new DateTimeImmutable());

        $this->assertFalse($decision->allowed);
        $this->assertSame(5, $decision->limit);
        $this->assertSame('Limit reached.', $decision->message);
    }

    public function test_an_unregistered_key_is_a_programmer_error(): void
    {
        $meter = FakeUsageMeter::allowing();

        try {
            $this->quota($meter)->consume('unknown', 'u', new DateTimeImmutable());
            $this->fail('Expected LogicException.');
        } catch (LogicException $e) {
            $this->assertSame('Limit "unknown" is not registered.', $e->getMessage());
        }

        $this->assertSame([], $meter->units);
    }

    public function test_a_records_key_is_a_programmer_error(): void
    {
        $meter = FakeUsageMeter::allowing();

        $this->expectException(LogicException::class);

        try {
            $this->quota($meter)->consume('seats', 'u', new DateTimeImmutable());
        } finally {
            $this->assertSame([], $meter->units);
        }
    }

    public function test_an_operator_that_throws_is_reported_and_the_unit_is_allowed(): void
    {
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported): void {
            $reported[] = $e->getMessage();
        });

        $decision = $this->quota(FakeUsageMeter::failing(new RuntimeException('operator is down')))
            ->consume('visits', 'u', new DateTimeImmutable());

        $this->assertTrue($decision->allowed);
        $this->assertSame(['operator is down'], $reported);
    }

    public function test_the_default_meter_allows_everything(): void
    {
        $this->assertTrue($this->quota(new UnlimitedUsageMeter())->consume('visits', 'u', new DateTimeImmutable())->allowed);
    }

    public function test_the_default_binding_is_unlimited(): void
    {
        $app = new Application(base_path());
        (new DomainServiceProvider($app))->register();

        $this->assertInstanceOf(UnlimitedUsageMeter::class, $app->make(UsageMeterInterface::class));
    }

    public function test_a_binding_made_before_core_is_not_overwritten(): void
    {
        $operator = FakeUsageMeter::denying();
        $app      = new Application(base_path());
        $app->singleton(UsageMeterInterface::class, static fn (): UsageMeterInterface => $operator);

        (new DomainServiceProvider($app))->register();

        $this->assertSame($operator, $app->make(UsageMeterInterface::class));
    }

    public function test_the_container_resolves_the_wrapper(): void
    {
        $this->assertInstanceOf(PeriodQuota::class, $this->app->make(PeriodQuota::class));
    }

    private function quota(UsageMeterInterface $meter): PeriodQuota
    {
        $registry = new LimitRegistry();
        $registry->register(new LimitDefinition('visits', 'Visits', 'visits', LimitKind::PerPeriod));
        $registry->register(new LimitDefinition('seats', 'Seats', 'seats', LimitKind::Records));

        $context = new TenantContext();
        $context->set(new RuntimeTenant(id: self::TENANT_ID, schemaName: 'main'));

        return new PeriodQuota($registry, $meter, $context);
    }
}
