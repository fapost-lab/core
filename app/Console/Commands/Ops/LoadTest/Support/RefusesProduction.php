<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops\LoadTest\Support;

/**
 * Shared guard for every `loadtest:*` command: this tooling provisions and
 * deletes throwaway tenants and drives real queue workers — none of that
 * belongs anywhere near a production environment.
 */
trait RefusesProduction
{
    /**
     * Environments the load-test tooling may run in. An allow-list rather than
     * "not production": a differently named environment pointed at a real
     * database must not be provisioned into and cleaned up by accident.
     *
     * @var list<string>
     */
    private const array LOAD_TEST_ENVIRONMENTS = ['local', 'loadtest', 'staging', 'testing'];

    private function refuseInProduction(): bool
    {
        if (app()->environment(self::LOAD_TEST_ENVIRONMENTS)) {
            return false;
        }

        $this->components->error(sprintf(
            'Load-test tooling runs only in %s environments (this one is "%s").',
            implode(', ', self::LOAD_TEST_ENVIRONMENTS),
            app()->environment(),
        ));

        return true;
    }
}
