<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Assistant\Models\Assistant;

/**
 * One application of the shell's rail, as {@see ApplicationRegistry} gives it for a user: where it opens, how it is
 * named and drawn, and whether it works per assistant (then it carries the assistant it opens on).
 */
final readonly class ShellApplication
{
    public function __construct(
        public string $key,
        public string $label,
        public string $short,
        public string $scope,
        public string $icon,
        public string $href,
        public bool $external,
        public bool $pinnedByDefault,
        public ?Assistant $assistant = null,
    ) {
    }

    /**
     * Whether the application works per assistant: only then does the shell show the assistant switcher.
     */
    public function isPerAssistant(): bool
    {
        return null !== $this->assistant;
    }

    /**
     * @return array{key: string, label: string, short: string, scope: string, icon: string, href: string, external: bool, perAssistant: bool}
     */
    public function toArray(): array
    {
        return [
            'key'          => $this->key,
            'label'        => $this->label,
            'short'        => $this->short,
            'scope'        => $this->scope,
            'icon'         => $this->icon,
            'href'         => $this->href,
            'external'     => $this->external,
            'perAssistant' => $this->isPerAssistant(),
        ];
    }
}
