<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Templates\TemplateExpander;
use PHPUnit\Framework\TestCase;

/**
 * The builder keeps its own copy of the template parameter rules (`resources/js/builder/utils/templateNodes.ts`); the
 * server is the authority. Both sides are checked against `tests/Fixtures/flow-template-parameter-rules.json`, so a
 * change on one side alone turns that side's test red (`templateNodes.test.ts` is the other).
 */
final class TemplateParameterRulesParityTest extends TestCase
{
    /** @var array{exposable_field_types: list<string>, non_parameterizable_node_types: list<string>, parameter_key_pattern: string, empty_value_cases: array{empty: list<mixed>, not_empty: list<mixed>}} */
    private array $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rules = json_decode((string) file_get_contents(__DIR__ . '/../../../Fixtures/flow-template-parameter-rules.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_exposable_field_types_and_the_blocked_node_types_match_the_fixture(): void
    {
        $this->assertSame($this->rules['exposable_field_types'], TemplateExpander::EXPOSABLE_FIELD_TYPES);
        $this->assertSame($this->rules['non_parameterizable_node_types'], TemplateExpander::NON_PARAMETERIZABLE_NODE_TYPES);
    }

    public function test_the_parameter_key_pattern_matches_the_fixture(): void
    {
        $this->assertSame('/' . $this->rules['parameter_key_pattern'] . '/', TemplateExpander::PARAMETER_KEY_PATTERN);
    }

    public function test_the_emptiness_rule_matches_the_fixture_cases(): void
    {
        foreach ($this->rules['empty_value_cases']['empty'] as $case) {
            $this->assertTrue(TemplateExpander::isEmptyParameterValue($case), 'empty: ' . json_encode($case));
        }

        foreach ($this->rules['empty_value_cases']['not_empty'] as $case) {
            $this->assertFalse(TemplateExpander::isEmptyParameterValue($case), 'not empty: ' . json_encode($case));
        }
    }
}
