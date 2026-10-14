<?php

declare(strict_types=1);

namespace App\Domains\Solution\DTO;

/**
 * What reading a Solution's `fapost-ui.json` from its package found: the bundle and where it lies, or every problem.
 */
final readonly class SolutionUiSource
{
    /**
     * @param  string|null  $directory  the directory of `fapost-ui.json` (the Solution's `dist/`); only the files in $files are published from it
     * @param  string|null  $manifestFile  absolute path of `fapost-ui.json`
     * @param  list<string>  $problems  lines an operator reads, each starting with the Solution id; empty exactly when $bundle is set
     * @param  list<string>  $files  what publication copies, relative to $directory: static assets and `fapost-ui.json`
     * @param  list<string>  $warnings  visible files publication leaves behind, one line each for the operator
     */
    public function __construct(
        public string $solutionId,
        public ?string $directory,
        public ?string $manifestFile,
        public ?SolutionUiBundle $bundle,
        public array $problems,
        public array $files = [],
        public array $warnings = [],
    ) {
    }

    /**
     * @param  list<string>  $problems
     */
    public static function invalid(string $solutionId, ?string $manifestFile, array $problems): self
    {
        return new self($solutionId, null === $manifestFile ? null : dirname($manifestFile), $manifestFile, null, $problems);
    }
}
