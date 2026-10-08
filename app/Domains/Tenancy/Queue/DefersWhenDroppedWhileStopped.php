<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Queue;

/**
 * A gated job that is dropped while its tenant is stopped, but whose work stays recorded elsewhere
 * (a recipient row still Pending) and has to be picked up once the tenant is active. It arranges
 * that here, at most once for the whole group of jobs it belongs to.
 */
interface DefersWhenDroppedWhileStopped
{
    public function deferUntilActive(): void;
}
