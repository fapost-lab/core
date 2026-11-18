<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Services\VariableSchemaCollector;
use App\Domains\Flow\Validation\ListSizeChecker;
use PHPUnit\Framework\TestCase;

final class ListSizeCheckerTest extends TestCase
{
    public function test_a_whole_number_in_range_on_a_contact_list_passes(): void
    {
        $this->assertSame([], $this->check($this->input('photos', 'contact', ['item_type' => 'photo', 'max_size' => 250])));
        $this->assertSame([], $this->check($this->input('photos', 'contact', ['item_type' => 'photo'])));
    }

    public function test_out_of_range_and_non_integer_values_are_errors_naming_the_node(): void
    {
        foreach ([0, 1001, -5, '50', 2.5] as $bad) {
            $errors = $this->check($this->input('photos', 'contact', ['max_size' => $bad]));

            $this->assertCount(1, $errors, var_export($bad, true));
            $this->assertSame('list_max_size_invalid', $errors[0]->code);
            $this->assertSame('nodes.ask', $errors[0]->path);
        }
    }

    public function test_a_session_list_with_max_size_is_an_error(): void
    {
        $errors = $this->check($this->input('photos', 'session', ['max_size' => 10]));

        $this->assertSame(['list_max_size_invalid'], array_map(static fn ($e): string => $e->code, $errors));
        $this->assertStringContainsString('contact', $errors[0]->message);
    }

    /**
     * @param  array<string, mixed>  $properties
     *
     * @return array<string, array<string, mixed>>
     */
    private function input(string $name, string $storage, array $properties): array
    {
        return ['ask' => [
            'id'     => 'ask',
            'type'   => 'input',
            'config' => ['variable' => ['name' => $name, 'storage' => $storage, 'type' => 'array', 'properties' => $properties]],
        ]];
    }

    /**
     * @param  array<string, array<string, mixed>>  $nodeMap
     *
     * @return list<\App\Domains\Flow\DTOs\FlowValidationErrorDto>
     */
    private function check(array $nodeMap): array
    {
        return (new ListSizeChecker(new VariableSchemaCollector()))->check($nodeMap);
    }
}
