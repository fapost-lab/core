<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\DTO;

/**
 * The onboarding checklist as one user sees it: every registered step in the wizard's order.
 */
final readonly class ChecklistView
{
    /**
     * @param  list<array{key: string, required: bool, complete: bool, url: string}>  $items
     */
    public function __construct(public array $items)
    {
    }

    /**
     * How many steps are still open.
     */
    public function remaining(): int
    {
        return count(array_filter($this->items, static fn (array $item): bool => ! $item['complete']));
    }
}
