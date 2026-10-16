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
     * @param  string|null  $kit  range of the console kit the bundle was built against; null in a cache older than this field
     * @param  string|null  $vue  range of Vue it was built against
     * @param  string|null  $inertia  range of Inertia it was built against
     */
    public function __construct(
        public string $solutionId,
        public string $hash,
        public array $pages,
        public array $components,
        public array $css,
        public ?string $kit = null,
        public ?string $vue = null,
        public ?string $inertia = null,
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
            kit: isset($data['kit']) ? (string) $data['kit'] : null,
            vue: isset($data['vue']) ? (string) $data['vue'] : null,
            inertia: isset($data['inertia']) ? (string) $data['inertia'] : null,
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
     * @return array{solutionId: string, hash: string, pages: array<string, string>, components: array<string, string>, css: list<string>, kit: string|null, vue: string|null, inertia: string|null}
     */
    public function toArray(): array
    {
        return [
            'solutionId' => $this->solutionId,
            'hash'       => $this->hash,
            'pages'      => $this->pages,
            'components' => $this->components,
            'css'        => $this->css,
            'kit'        => $this->kit,
            'vue'        => $this->vue,
            'inertia'    => $this->inertia,
        ];
    }
}
