<?php

declare(strict_types=1);

namespace App\Http\DataTable;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Staff\Models\User;
use App\Http\Shell\SolutionExtensions;
use Fapost\Foundation\Console\Contracts\ColumnValuesInterface;
use Fapost\Foundation\Console\DTO\ColumnContext;
use Fapost\Foundation\Console\DTO\TableColumn;
use Fapost\Foundation\Console\Enums\TableTarget;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

/**
 * Fills the columns Solutions add to a Core table (ADR solution-console-ui): for every column the user sees, the
 * Solution's {@see ColumnValuesInterface} is asked once for the whole page, and each row gets the value under
 * `<id>.<key>`. A Solution that throws or answers garbage leaves its column empty and is logged; Core's table still
 * renders.
 *
 * There is no timeout: `values()` runs synchronously inside Core's request and PHP cannot interrupt it, so a slow one
 * slows Core's table. The contract asks Solutions to keep it fast — one batched query for the page's ids, no remote
 * calls — and the published docs say so (design §4 promised "exception or timeout"; decided in review F8).
 */
final readonly class SolutionColumns
{
    public function __construct(
        private SolutionExtensions $extensions,
        private CurrentAssistantInterface $assistant,
        private Container $container,
    ) {
    }

    /**
     * @param  list<array<string, mixed>>  $rows  each with its record id under `id`
     *
     * @return list<array<string, mixed>>
     */
    public function fill(TableTarget $target, array $rows, ?User $user): array
    {
        if (null === $user || [] === $rows) {
            return $rows;
        }

        $columns = array_values(array_filter(
            $this->extensions->visible($user, TableColumn::class),
            static fn ($column): bool => $column->declaration->target === $target,
        ));

        if ([] === $columns) {
            return $rows;
        }

        $ids       = array_map(static fn (array $row): string => (string) $row['id'], $rows);
        $assistant = $this->assistant->isResolved() ? (string) $this->assistant->get()->getKey() : null;

        foreach ($columns as $column) {
            $values = $this->values($column->solutionId, $column->declaration, $ids, $assistant);
            $field  = $column->id();

            foreach ($rows as $index => $row) {
                $rows[$index][$field] = $values[(string) $row['id']] ?? null;
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $ids
     *
     * @return array<string, string|int|float|bool|null>
     */
    private function values(string $solutionId, TableColumn $column, array $ids, ?string $assistant): array
    {
        try {
            $source = $this->container->make($column->values);

            if (! $source instanceof ColumnValuesInterface) {
                throw new UnexpectedValueException(sprintf('%s does not implement %s.', $column->values, ColumnValuesInterface::class));
            }

            $values = $source->values($ids, new ColumnContext($column->target, $column->key, $assistant));
        } catch (Throwable $exception) {
            Log::error('A Solution table column failed; the column is left empty.', [
                'solution'  => $solutionId,
                'column'    => $column->key,
                'table'     => $column->target->value,
                'exception' => $exception,
            ]);

            return [];
        }

        return array_filter($values, static fn (mixed $value): bool => null === $value || is_scalar($value));
    }
}
