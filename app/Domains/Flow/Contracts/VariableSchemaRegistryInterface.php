<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\State\Variables\VariableType;

/**
 * Per-tenant registry of declared variable types.
 *
 * Populated at publish time from all active flow definitions; cached per-request.
 * Consumers use this to resolve the declared type of a variable before coercion.
 */
interface VariableSchemaRegistryInterface
{
    /**
     * Return the declared type for the given (storage, group, name) triple,
     * or null if the variable is unknown (legacy / undeclared).
     *
     * @param  string       $storage  'session' | 'contact'
     * @param  string|null  $group    null for root-level variables
     */
    public function get(string $storage, ?string $group, string $name): ?VariableType;

    /**
     * Return all declared variables for the current tenant.
     *
     * @return array<string, VariableType> Keyed as "{storage}:{group}:{name}" (group may be empty string)
     */
    public function getAllForTenant(): array;

    /**
     * Invalidate the cached schema. Called by PublishFlowService after upsert.
     */
    public function invalidate(): void;
}
