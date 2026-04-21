<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers\Abstract;

use FAPost\Foundation\Contracts\NodeHandlerInterface;
use LogicException;

abstract class AbstractVersionedHandler implements NodeHandlerInterface
{
    public function type(): string
    {
        if ( ! defined(static::class . '::TYPE')) {
            throw new LogicException(static::class . ' must define TYPE constant');
        }

        /** @var string $type */
        $type = constant(static::class . '::TYPE');

        return $type;
    }

    /**
     * @return array<int>
     */
    public function supportedVersions(): array
    {
        return [$this->version()];
    }

    public function label(): string
    {
        return ucfirst(str_replace('_', ' ', $this->type()));
    }

    public function category(): string
    {
        return 'Core';
    }

    /**
     * @return array<string, mixed>
     */
    public function configSchema(): array
    {
        return [];
    }
}
