<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Solution;

use App\Domains\Solution\DTO\SolutionUiBundle;
use App\Domains\Solution\DTO\UiRangeMismatch;
use App\Domains\Solution\Services\SolutionUiCompatibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SolutionUiCompatibilityTest extends TestCase
{
    private const array RUNTIME = ['kit' => '0.1.0', 'vue' => '3.5.43', 'inertia' => '3.7.1'];

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function kitRanges(): iterable
    {
        yield 'caret' => ['^0.1', true];
        yield 'caret next minor' => ['^0.2', false];
        yield 'comma and' => ['>=0.1,<0.3', true];
        yield 'comma and above' => ['>=0.2,<0.3', false];
        yield 'space and' => ['>=0.1 <0.3', true];
        yield 'or' => ['^0.2 || ^0.1', true];
        yield 'tilde kit minor' => ['~0.1.0', true];
        yield 'tilde' => ['~1.2', false];
        yield 'wildcard' => ['0.1.*', true];
        yield 'exact' => ['0.1.0', true];
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function majorRanges(): iterable
    {
        yield 'newer minor, same major' => ['^3.6', true];
        yield 'shipped 3.5.43 and 3.7.1, range inside 3.x' => ['>=3.6,<3.7', true];
        yield 'next major' => ['^4.0', false];
        yield 'previous major' => ['^2.9', false];
        yield 'at least 4' => ['>=4', false];
        yield 'below 4' => ['<4', true];
        yield 'or' => ['^2.0 || ^3.0', true];
    }

    public function test_a_bundle_inside_every_range_has_no_mismatch(): void
    {
        $this->assertSame([], $this->check($this->bundle('^0.1', '^3.5', '^3.0')));
    }

    public function test_each_runtime_outside_its_range_is_reported_in_a_fixed_order(): void
    {
        $mismatches = $this->check($this->bundle('^0.2', '^3.5', '^4.0'));

        $this->assertEquals([
            new UiRangeMismatch('kit', '^0.2', '0.1.0'),
            new UiRangeMismatch('inertia', '^4.0', '3.7.1'),
        ], $mismatches);
    }

    #[DataProvider('kitRanges')]
    public function test_the_kit_range_uses_composer_syntax(string $range, bool $fits): void
    {
        $this->assertSame($fits, [] === $this->check($this->bundle($range, '^3.5', '^3.0')), $range);
    }

    #[DataProvider('majorRanges')]
    public function test_vue_and_inertia_are_checked_by_major_only(string $range, bool $fits): void
    {
        $this->assertSame($fits, [] === $this->check($this->bundle('^0.1', $range, $range)), $range);
    }

    public function test_an_unreadable_range_is_a_refusal_for_every_component(): void
    {
        $this->assertEquals([
            new UiRangeMismatch('kit', 'latest', '0.1.0', true),
            new UiRangeMismatch('vue', 'x y z', '3.5.43', true),
            new UiRangeMismatch('inertia', '', '3.7.1', true),
        ], $this->check($this->bundle('latest', 'x y z', '')));
    }

    public function test_a_range_that_cannot_be_read_is_not_taken_for_a_fit(): void
    {
        $this->assertEquals([new UiRangeMismatch('vue', 'next', '3.5.43', true)], $this->check($this->bundle('^0.1', 'next', '^3.0')));
    }

    public function test_a_bundle_from_an_old_cache_without_ranges_reports_nothing(): void
    {
        $this->assertSame([], $this->check(new SolutionUiBundle('showcase', 'abc', ['A' => 'a.js'], [], [])));
    }

    public function test_the_ranges_survive_the_compiled_cache(): void
    {
        $bundle = SolutionUiBundle::fromArray($this->bundle('^0.1', '^3.5', '^3.0')->toArray());

        $this->assertSame(['^0.1', '^3.5', '^3.0'], [$bundle->kit, $bundle->vue, $bundle->inertia]);
        $this->assertNull(SolutionUiBundle::fromArray(['solutionId' => 's', 'hash' => 'h', 'pages' => [], 'components' => [], 'css' => []])->kit);
    }

    /**
     * @return list<UiRangeMismatch>
     */
    private function check(SolutionUiBundle $bundle): array
    {
        return (new SolutionUiCompatibility(self::RUNTIME))->mismatches($bundle);
    }

    private function bundle(string $kit, string $vue, string $inertia): SolutionUiBundle
    {
        return new SolutionUiBundle('showcase', 'abc', ['A' => 'a.js'], [], [], $kit, $vue, $inertia);
    }
}
