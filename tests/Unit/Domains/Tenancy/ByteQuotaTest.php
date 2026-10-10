<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Services\ByteQuota;
use App\Domains\Tenancy\Services\LimitRegistry;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Fapost\Foundation\Quota\DTO\LimitDefinition;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Illuminate\Contracts\Debug\ExceptionHandler;
use LogicException;
use RuntimeException;
use Tests\Support\FakeTenantLimits;
use Tests\TestCase;

final class ByteQuotaTest extends TestCase
{
    private const string TENANT_ID = '01k00000000000000000000000';

    public function test_it_returns_the_limit_in_bytes(): void
    {
        $this->assertSame(5_000_000_000, $this->quota(new FakeTenantLimits(['storage' => 5_000_000_000]))->limit('storage'));
    }

    public function test_no_limit_is_null(): void
    {
        $this->assertNull($this->quota(new FakeTenantLimits())->limit('storage'));
    }

    public function test_an_unregistered_key_is_a_programmer_error(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Limit "unknown" is not registered.');

        $this->quota(new FakeTenantLimits())->limit('unknown');
    }

    public function test_a_key_that_is_not_a_byte_key_is_a_programmer_error(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Limit "seats" is not a byte limit.');

        $this->quota(new FakeTenantLimits(['seats' => 3]))->limit('seats');
    }

    public function test_an_operator_failure_propagates_by_default(): void
    {
        $limits          = new FakeTenantLimits();
        $limits->failure = new RuntimeException('operator is down');

        $this->expectExceptionMessage('operator is down');

        $this->quota($limits)->limit('storage');
    }

    public function test_an_operator_failure_is_reported_and_means_no_limit_when_failing_open(): void
    {
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (RuntimeException $e) use (&$reported): void {
            $reported[] = $e->getMessage();
        });

        $limits          = new FakeTenantLimits(['storage' => 10]);
        $limits->failure = new RuntimeException('operator is down');

        $this->assertNull($this->quota($limits)->limit('storage', failOpen: true));
        $this->assertSame(['operator is down'], $reported);
    }

    private function quota(FakeTenantLimits $limits): ByteQuota
    {
        $registry = new LimitRegistry();
        $registry->register(new LimitDefinition('storage', 'Storage', 'bytes', LimitKind::Bytes));
        $registry->register(new LimitDefinition('seats', 'Seats', 'seats', LimitKind::Records));

        $context = new TenantContext();
        $context->set(new RuntimeTenant(id: self::TENANT_ID, schemaName: 'main'));

        return new ByteQuota($registry, $limits, $context);
    }
}
