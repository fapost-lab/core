<?php

declare(strict_types=1);

namespace App\Domains\Solution\DTO;

/**
 * One runtime a Solution's console UI bundle was built for that this Core does not provide.
 */
final readonly class UiRangeMismatch
{
    /**
     * @param  string  $component  `kit`, `vue` or `inertia`: the field of `fapost-ui.json`
     * @param  string  $required  the range the bundle declares
     * @param  string  $provided  the version this Core ships
     * @param  bool  $unreadable  the range is not one Core can evaluate, so it cannot say the bundle fits
     */
    public function __construct(
        public string $component,
        public string $required,
        public string $provided,
        public bool $unreadable = false,
    ) {
    }
}
