<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Enums\RefusedWork;
use App\Domains\Tenancy\Events\LimitReached;
use App\Domains\Tenancy\Services\LimitAnnouncer;
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
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
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

    public function test_a_refusal_is_logged_without_the_unit_key(): void
    {
        Log::spy();

        $this->quota(FakeUsageMeter::denying(5, 5))->consume('visits', 'contact:secret', new DateTimeImmutable());

        Log::shouldHaveReceived('info')->once()->with('quota.volume.refused', [
            'tenant_id' => self::TENANT_ID,
            'key'       => 'visits',
            'limit'     => 5,
            'used'      => 5,
        ]);
    }

    public function test_an_allowed_unit_is_not_logged(): void
    {
        Log::spy();

        $this->quota(FakeUsageMeter::allowing())->consume('visits', 'u', new DateTimeImmutable());

        Log::shouldNotHaveReceived('info');
    }

    public function test_a_refusal_announces_the_limit_with_what_was_turned_away_and_the_operators_period(): void
    {
        Event::fake([LimitReached::class]);
        $end = new DateTimeImmutable('2026-11-01T00:00:00+00:00');

        $this->quota(FakeUsageMeter::denying(5, 5, $end))
            ->consume('visits', 'u', new DateTimeImmutable(), RefusedWork::InboundMessage);

        Event::assertDispatchedTimes(LimitReached::class, 1);
        Event::assertDispatched(LimitReached::class, static fn (LimitReached $event): bool => self::TENANT_ID === $event->tenantId
            && 'visits' === $event->key
            && LimitKind::PerPeriod === $event->kind
            && 5 === $event->limit
            && 5 === $event->used
            && RefusedWork::InboundMessage === $event->refused
            && $end === $event->periodEndsAt);
    }

    public function test_a_refusal_without_a_stated_kind_of_work_is_other(): void
    {
        Event::fake([LimitReached::class]);

        $this->quota(FakeUsageMeter::denying(5, 5))->consume('visits', 'u', new DateTimeImmutable());

        Event::assertDispatched(LimitReached::class, static fn (LimitReached $event): bool => RefusedWork::Other === $event->refused && null === $event->periodEndsAt);
    }

    public function test_an_allowed_unit_and_an_operator_failure_announce_nothing(): void
    {
        Event::fake([LimitReached::class]);

        $this->quota(FakeUsageMeter::allowing())->consume('visits', 'u', new DateTimeImmutable());
        $this->quota(FakeUsageMeter::failing(new RuntimeException('down')))->consume('visits', 'u', new DateTimeImmutable());

        Event::assertNotDispatched(LimitReached::class);
    }

    public function test_a_failing_listener_does_not_change_the_refusal(): void
    {
        $this->app->make(ExceptionHandler::class)->reportable(static fn (RuntimeException $e): bool => false);
        $this->app->make(Dispatcher::class)->listen(LimitReached::class, static function (): never {
            throw new RuntimeException('listener is down');
        });

        $decision = $this->quota(FakeUsageMeter::denying(5, 5))->consume('visits', 'u', new DateTimeImmutable());

        $this->assertFalse($decision->allowed);
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

        return new PeriodQuota($registry, $meter, $context, new LimitAnnouncer($this->app->make(Dispatcher::class), $context));
    }
}
