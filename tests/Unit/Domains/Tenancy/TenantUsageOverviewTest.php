<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Enums\UsageState;
use App\Domains\Tenancy\Services\LimitRegistry;
use App\Domains\Tenancy\Services\NoPeriodUsage;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantUsageCounters;
use App\Domains\Tenancy\Services\TenantUsageOverview;
use App\Domains\Tenancy\Services\UnlimitedTenantLimits;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Domains\Tenancy\ValueObjects\UsageRow;
use Closure;
use DateTimeImmutable;
use Fapost\Foundation\Quota\Contracts\PeriodUsageInterface;
use Fapost\Foundation\Quota\Contracts\TenantLimitsInterface;
use Fapost\Foundation\Quota\DTO\LimitDefinition;
use Fapost\Foundation\Quota\DTO\PeriodUsage;
use Fapost\Foundation\Quota\DTO\PeriodUsageReport;
use Fapost\Foundation\Quota\Enums\LimitKind;
use Illuminate\Contracts\Debug\ExceptionHandler;
use RuntimeException;
use Tests\Support\FakePeriodUsage;
use Tests\Support\FakeTenantLimits;
use Tests\TestCase;

/**
 * What the usage page is built from: the state of each limit, decided in one place, and a faulty operator or counter
 * that turns rows into "unavailable" instead of breaking the page or passing for "no limit".
 */
final class TenantUsageOverviewTest extends TestCase
{
    private const string TENANT_ID = '01K00000000000000000000000';

    /**
     * @return array<string, array{int, ?int, UsageState, ?int}>
     */
    public static function recordStates(): array
    {
        return [
            'no limit'                    => [3, null, UsageState::Unlimited, null],
            'far below'                   => [3, 10, UsageState::Ok, 30],
            'just below the threshold'    => [79, 100, UsageState::Ok, 79],
            'at the threshold'            => [80, 100, UsageState::Near, 80],
            'one place left'              => [9, 10, UsageState::Near, 90],
            'at the limit'                => [10, 10, UsageState::Reached, 100],
            'over the limit'              => [14, 10, UsageState::Reached, 100],
            'limit zero, nothing used'    => [0, 0, UsageState::Reached, 100],
            'limit zero, something there' => [2, 0, UsageState::Reached, 100],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('recordStates')]
    public function test_a_records_row_has_the_state_the_threshold_gives(int $used, ?int $limit, UsageState $state, ?int $percent): void
    {
        $row = $this->overview(
            definitions: [$this->records('assistants')],
            limits: new FakeTenantLimits(['assistants' => $limit]),
            counters: ['assistants' => static fn (): int => $used],
        )->overview('en')->rows[0];

        $this->assertSame($state, $row->state);
        $this->assertSame($used, $row->used);
        $this->assertSame($limit, $row->limit);
        $this->assertSame(null === $limit ? null : $percent, $row->percent);
    }

    public function test_a_row_without_a_limit_and_without_a_count_is_not_shown(): void
    {
        $view = $this->overview(definitions: [$this->records('solution_key')], limits: new UnlimitedTenantLimits())->overview('en');

        $this->assertSame([], $view->rows);
        $this->assertFalse($view->limited);
    }

    public function test_a_self_hosted_installation_lists_what_it_counts_as_unlimited(): void
    {
        $view = $this->overview(
            definitions: [$this->records('assistants'), $this->perPeriod('calls')],
            limits: new UnlimitedTenantLimits(),
            counters: ['assistants' => static fn (): int => 2],
        )->overview('en');

        $this->assertSame(['assistants'], array_map(static fn (UsageRow $row): string => $row->key, $view->rows));
        $this->assertSame(UsageState::Unlimited, $view->rows[0]->state);
        $this->assertFalse($view->limited);
        $this->assertNull($view->action);
    }

    public function test_the_operator_report_supplies_used_limit_period_and_the_button(): void
    {
        $operator = new FakePeriodUsage(new PeriodUsageReport(
            [new PeriodUsage('calls', 90, 100, new DateTimeImmutable('2026-10-12'), new DateTimeImmutable('2026-11-12'))],
            'Upgrade plan',
            'https://example.test/billing',
        ));

        $view = $this->overview(
            definitions: [$this->perPeriod('calls')],
            limits: new FakeTenantLimits(['calls' => 5]),
            operator: $operator,
        )->overview('ru');

        $row = $view->rows[0];
        $this->assertSame(UsageState::Near, $row->state);
        $this->assertSame(90, $row->used);
        // The limit comes from the same snapshot as the count, not from the (cached) limit answer.
        $this->assertSame(100, $row->limit);
        $this->assertSame('2026-11-12', $row->periodEndsAt?->format('Y-m-d'));
        $this->assertSame(['label' => 'Upgrade plan', 'url' => 'https://example.test/billing'], $view->action);
        $this->assertTrue($view->limited);
        $this->assertSame([['tenantId' => mb_strtolower(self::TENANT_ID), 'locale' => 'ru']], $operator->calls);
    }

    public function test_the_operator_is_asked_once_however_many_keys_there_are(): void
    {
        $operator = new FakePeriodUsage();

        $this->overview(
            definitions: [$this->perPeriod('calls'), $this->perPeriod('messages'), $this->records('assistants')],
            limits: new FakeTenantLimits(['calls' => 1, 'messages' => 1]),
            operator: $operator,
        )->overview('en');

        $this->assertCount(1, $operator->calls);
    }

    public function test_the_operator_is_not_asked_without_a_per_period_key(): void
    {
        $operator = new FakePeriodUsage();

        $this->overview(definitions: [$this->records('assistants')], operator: $operator)->overview('en');

        $this->assertSame([], $operator->calls);
    }

    public function test_a_per_period_key_the_operator_did_not_report_is_not_reported_with_its_limit(): void
    {
        $row = $this->overview(
            definitions: [$this->perPeriod('calls')],
            limits: new FakeTenantLimits(['calls' => 50]),
            operator: new FakePeriodUsage(new PeriodUsageReport()),
        )->overview('en')->rows[0];

        $this->assertSame(UsageState::NotReported, $row->state);
        $this->assertNull($row->used);
        $this->assertSame(50, $row->limit);
    }

    public function test_an_entry_for_a_key_that_is_not_a_registered_per_period_key_is_ignored(): void
    {
        $now      = new DateTimeImmutable('2026-10-12');
        $operator = new FakePeriodUsage(new PeriodUsageReport([
            new PeriodUsage('assistants', 9, 9, $now, $now->modify('+1 month')),
            new PeriodUsage('unknown', 9, 9, $now, $now->modify('+1 month')),
        ]));

        $view = $this->overview(
            definitions: [$this->records('assistants'), $this->perPeriod('calls')],
            limits: new FakeTenantLimits(['assistants' => 5]),
            counters: ['assistants' => static fn (): int => 1],
            operator: $operator,
        )->overview('en');

        $this->assertSame(['assistants'], array_map(static fn (UsageRow $row): string => $row->key, $view->rows));
        $this->assertSame(1, $view->rows[0]->used);
    }

    public function test_an_operator_that_throws_is_reported_and_its_rows_are_unavailable(): void
    {
        $reported = $this->collectReports();

        $view = $this->overview(
            definitions: [$this->records('assistants'), $this->perPeriod('calls')],
            limits: new FakeTenantLimits(['assistants' => 5]),
            counters: ['assistants' => static fn (): int => 1],
            operator: new FakePeriodUsage(failure: new RuntimeException('operator is down')),
        )->overview('en');

        $this->assertSame(['operator is down'], $reported->messages);
        $this->assertSame(UsageState::Ok, $view->rows[0]->state);
        $this->assertSame(UsageState::Unavailable, $view->rows[1]->state);
        $this->assertNull($view->rows[1]->limit);
        $this->assertTrue($view->limited);
    }

    public function test_a_limit_that_cannot_be_read_is_unavailable_not_unlimited(): void
    {
        $reported        = $this->collectReports();
        $limits          = new FakeTenantLimits();
        $limits->failure = new RuntimeException('limits are down');

        $view = $this->overview(
            definitions: [$this->records('assistants')],
            limits: $limits,
            counters: ['assistants' => static fn (): int => 4],
        )->overview('en');

        $this->assertSame(['limits are down'], $reported->messages);
        $this->assertSame(UsageState::Unavailable, $view->rows[0]->state);
        $this->assertSame(4, $view->rows[0]->used);
        $this->assertTrue($view->limited);
    }

    public function test_a_counter_that_throws_is_reported_and_its_row_is_unavailable(): void
    {
        $reported = $this->collectReports();

        $view = $this->overview(
            definitions: [$this->records('assistants'), $this->records('flows')],
            limits: new FakeTenantLimits(['assistants' => 5, 'flows' => 5]),
            counters: [
                'assistants' => static fn (): int => throw new RuntimeException('count failed'),
                'flows'      => static fn (): int => 2,
            ],
        )->overview('en');

        $this->assertSame(['count failed'], $reported->messages);
        $this->assertSame(UsageState::Unavailable, $view->rows[0]->state);
        $this->assertSame(UsageState::Ok, $view->rows[1]->state);
    }

    public function test_an_incomplete_action_is_dropped(): void
    {
        $view = $this->overview(
            definitions: [$this->perPeriod('calls')],
            limits: new FakeTenantLimits(['calls' => 5]),
            operator: new FakePeriodUsage(new PeriodUsageReport()),
        )->overview('en');

        $this->assertNull($view->action);
    }

    public function test_the_default_reports_nothing(): void
    {
        $this->assertNull((new NoPeriodUsage())->reportFor(self::TENANT_ID, 'en'));
    }

    /**
     * @param  list<LimitDefinition>  $definitions
     * @param  array<string, Closure(): int>  $counters
     */
    private function overview(
        array $definitions,
        ?TenantLimitsInterface $limits = null,
        array $counters = [],
        ?PeriodUsageInterface $operator = null,
    ): TenantUsageOverview {
        $registry = new LimitRegistry();
        $store    = new TenantUsageCounters($registry);

        foreach ($definitions as $definition) {
            $registry->register($definition);
        }

        foreach ($counters as $key => $counter) {
            $store->register($key, $counter);
        }

        $context = new TenantContext();
        $context->set(new RuntimeTenant(self::TENANT_ID, 'main'));

        return new TenantUsageOverview($context, $registry, $limits ?? new UnlimitedTenantLimits(), $operator ?? new NoPeriodUsage(), $store);
    }

    private function records(string $key): LimitDefinition
    {
        return new LimitDefinition($key, ucfirst($key), $key, LimitKind::Records);
    }

    private function perPeriod(string $key): LimitDefinition
    {
        return new LimitDefinition($key, ucfirst($key), $key, LimitKind::PerPeriod);
    }

    private function collectReports(): object
    {
        $reports = new class () {
            /** @var list<string> */
            public array $messages = [];
        };

        $this->app->make(ExceptionHandler::class)->reportable(function (RuntimeException $e) use ($reports): bool {
            $reports->messages[] = $e->getMessage();

            return false;
        });

        return $reports;
    }
}
