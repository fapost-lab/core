<?php

declare(strict_types=1);

namespace App\Infrastructure\Broadcasting;

use Illuminate\Contracts\Config\Repository;

/**
 * Whether Laravel broadcasting goes anywhere. `null` (Core's default) and `log` deliver to nobody, so with them the
 * server raises no broadcast event and the browser polls instead of subscribing.
 *
 * Read per call from the configuration it is built with; not a singleton (see conventions/worker-safety.md).
 */
final readonly class BroadcasterStatus
{
    /** @var list<string> */
    private const array INERT = ['', 'null', 'log'];

    public function __construct(
        private Repository $config,
    ) {
    }

    public function name(): string
    {
        $name = $this->config->get('broadcasting.default', 'null');

        return is_string($name) ? $name : 'null';
    }

    public function enabled(): bool
    {
        return ! in_array($this->name(), self::INERT, true);
    }
}
