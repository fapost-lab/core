<?php

declare(strict_types=1);

namespace App\Domains\Solution\DTO;

/**
 * A Solution's built console UI as Core serves it: the files its `fapost-ui.json` lists and the hash that names the
 * published directory `public/solutions/<id>/<hash>/`. The hash is taken from `fapost-ui.json`, which lists hashed
 * file names, so every new build gets a new directory and the browser may cache each file for good.
 */
final readonly class SolutionUiBundle
{
    /** Directory under `public/` that holds the published bundles, one subdirectory per Solution. */
    public const string PUBLIC_DIRECTORY = 'solutions';

    /**
     * @param  string  $hash  first 12 hex digits of the sha256 of `fapost-ui.json`
     * @param  array<string, string>  $pages  Path => file relative to the bundle directory
     * @param  array<string, string>  $components  Path => file relative to the bundle directory
     * @param  list<string>  $css  files relative to the bundle directory
     */
    public function __construct(
        public string $solutionId,
        public string $hash,
        public array $pages,
        public array $components,
        public array $css,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            solutionId: (string) $data['solutionId'],
            hash: (string) $data['hash'],
            pages: array_map(strval(...), (array) $data['pages']),
            components: array_map(strval(...), (array) $data['components']),
            css: array_values(array_map(strval(...), (array) $data['css'])),
        );
    }

    /**
     * The bundle directory relative to `public/`: `solutions/<id>/<hash>`.
     */
    public function directory(): string
    {
        return self::PUBLIC_DIRECTORY . '/' . $this->solutionId . '/' . $this->hash;
    }

    /**
     * @return array{solutionId: string, hash: string, pages: array<string, string>, components: array<string, string>, css: list<string>}
     */
    public function toArray(): array
    {
        return [
            'solutionId' => $this->solutionId,
            'hash'       => $this->hash,
            'pages'      => $this->pages,
            'components' => $this->components,
            'css'        => $this->css,
        ];
    }
}
