<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Subflow;

use App\Domains\Flow\Subflow\SubflowMappingValidator;
use Fapost\Foundation\Flow\DTO\NodeConfigIssue;
use PHPUnit\Framework\TestCase;

final class SubflowMappingValidatorTest extends TestCase
{
    public function test_a_node_without_mappings_has_no_issues(): void
    {
        $this->assertSame([], $this->issues([]));
        $this->assertSame([], $this->issues(['input_mapping' => [], 'output_mapping' => null]));
    }

    public function test_valid_mappings_have_no_issues(): void
    {
        $this->assertSame([], $this->issues([
            'input_mapping'  => ['user_id' => '{{flow.user_id}}', 'note' => 'plain text', 'empty' => ''],
            'output_mapping' => ['total' => 'price', '_tmp' => 'result_2'],
        ]));
    }

    public function test_a_mapping_that_is_not_a_map_is_refused(): void
    {
        $this->assertSame(
            [['subflow_invalid_input_mapping', 'input_mapping'], ['subflow_invalid_output_mapping', 'output_mapping']],
            $this->issues(['input_mapping' => 'user_id', 'output_mapping' => [['from' => 'a']]]),
        );
    }

    public function test_invalid_or_reserved_input_names_and_non_text_values_are_refused(): void
    {
        $this->assertSame(
            [
                ['subflow_invalid_input_name', 'input_mapping.1st'],
                ['subflow_invalid_input_name', 'input_mapping.id'],
                ['subflow_invalid_input_value', 'input_mapping.count'],
            ],
            $this->issues(['input_mapping' => ['1st' => 'x', 'id' => 'y', 'count' => ['nested']]]),
        );
    }

    public function test_invalid_output_names_and_sources_are_refused(): void
    {
        $this->assertSame(
            [
                ['subflow_invalid_output_name', 'output_mapping.flow.total'],
                ['subflow_invalid_output_source', 'output_mapping.price'],
                ['subflow_invalid_output_source', 'output_mapping.meta_ok'],
            ],
            $this->issues(['output_mapping' => [
                'flow.total' => 'price',
                'price'      => 'contact.price',
                'meta_ok'    => 7,
            ]]),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @return list<array{0: string, 1: string|null}>
     */
    private function issues(array $config): array
    {
        return array_map(
            static fn (NodeConfigIssue $issue): array => [$issue->code, $issue->field],
            (new SubflowMappingValidator())->validate($config),
        );
    }
}
