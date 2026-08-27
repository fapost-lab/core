<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

interface FlowTriggerConfigValidatorInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function validate(string $type, array $config): void;
}
