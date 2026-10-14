<?php

declare(strict_types=1);

namespace App\Domains\Solution\DTO;

/**
 * A Composer package that is, or claims to be, a Solution, as `vendor/composer/installed.json` lists it.
 */
final readonly class InstalledPackage
{
    /**
     * @param  string|null  $version  as Composer reports it, such as "1.2.0" or "dev-main"
     * @param  string|null  $type  `type` in installed.json
     * @param  array<string, mixed>|null  $composer  the package's composer.json, or null when it is not valid JSON
     * @param  string|null  $composerPath  absolute path of the composer.json that was read, null when the package ships none
     */
    public function __construct(
        public string $name,
        public ?string $version,
        public ?string $type,
        public ?array $composer,
        public ?string $composerPath,
    ) {
    }
}
