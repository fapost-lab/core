<?php

declare(strict_types=1);

namespace Tests\Fixtures\Solutions\Showcase;

use Fapost\Foundation\Console\Contracts\ColumnValuesInterface;
use Fapost\Foundation\Console\DTO\ColumnContext;
use RuntimeException;

/**
 * Values of the fixture's columns: `score` is the length of the row id, `mood` is "happy". Records every call, and
 * throws when told to, so a test can bind one instance and look.
 */
final class ShowcaseColumnValues implements ColumnValuesInterface
{
    /** @var list<array{ids: list<string>, key: string, assistant: string|null}> */
    public array $calls = [];

    public bool $fail = false;

    public function values(array $ids, ColumnContext $context): array
    {
        $this->calls[] = ['ids' => $ids, 'key' => $context->key, 'assistant' => $context->assistantId];

        if ($this->fail) {
            throw new RuntimeException('The showcase column broke.');
        }

        $values = [];

        foreach ($ids as $id) {
            $values[$id] = 'score' === $context->key ? mb_strlen($id) : 'happy';
        }

        return $values;
    }
}
