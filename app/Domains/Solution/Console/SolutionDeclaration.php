<?php

declare(strict_types=1);

namespace App\Domains\Solution\Console;

use Fapost\Foundation\Console\DTO\ContextAction;
use Fapost\Foundation\Console\DTO\DashboardWidget;
use Fapost\Foundation\Console\DTO\SettingsTab;
use Fapost\Foundation\Console\DTO\ShellElement;
use Fapost\Foundation\Console\DTO\TableColumn;

/**
 * One console declaration with the Solution it belongs to, as {@see ConsoleExtensionRegistry} keeps it.
 *
 * @template-covariant T of ContextAction|ShellElement|DashboardWidget|SettingsTab|TableColumn
 */
final readonly class SolutionDeclaration
{
    /**
     * @param  T  $declaration
     * @param  int  $sequence  registration order across the process; breaks ties after `order` and the Solution id
     */
    public function __construct(
        public string $solutionId,
        public ContextAction|ShellElement|DashboardWidget|SettingsTab|TableColumn $declaration,
        public int $sequence,
    ) {
    }

    /**
     * `<id>.<key>`: what tells two Solutions' declarations with the same key apart on the client.
     */
    public function id(): string
    {
        return $this->solutionId . '.' . $this->declaration->key;
    }
}
