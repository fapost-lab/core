<?php

declare(strict_types=1);

namespace App\Domains\Flow\State\Resolvers;

use LogicException;

final class ModuleResolutionContext
{
    private ?string $contactId = null;
    private ?string $tenantId  = null;

    public function set(string $contactId, string $tenantId): void
    {
        $this->contactId = $contactId;
        $this->tenantId  = $tenantId;
    }

    public function contactId(): string
    {
        if (null === $this->contactId) {
            throw new LogicException('Module resolution context contact_id is not resolved.');
        }

        return $this->contactId;
    }

    public function tenantId(): string
    {
        if (null === $this->tenantId) {
            throw new LogicException('Module resolution context tenant_id is not resolved.');
        }

        return $this->tenantId;
    }
}
