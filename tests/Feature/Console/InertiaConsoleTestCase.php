<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Env;
use Tests\Feature\FeatureTestCase;

/**
 * A feature test with the new Inertia console switched on (`UI_INERTIA=true`).
 *
 * `routes/inertia.php` is loaded while the application boots, so the switch is set as a real process
 * variable and the application is rebuilt, as {@see \Tests\Feature\Concerns\RunsInHostMode} does for the
 * tenancy mode; setting config after boot would be too late. A subclass may use `RunsInHostMode` as well: its
 * `refreshApplication()` calls this one through `parent`.
 */
abstract class InertiaConsoleTestCase extends FeatureTestCase
{
    /**
     * @var array{env: mixed, server: mixed, getenv: string|false}|null
     */
    private ?array $previousSwitch = null;

    protected function tearDown(): void
    {
        parent::tearDown();

        $previous = $this->previousSwitch;

        Env::getRepository()->clear('UI_INERTIA');
        $this->restore($_ENV, $previous['env'] ?? null);
        $this->restore($_SERVER, $previous['server'] ?? null);
        putenv(false === ($previous['getenv'] ?? false) ? 'UI_INERTIA' : 'UI_INERTIA=' . $previous['getenv']);
    }

    protected function refreshApplication(): void
    {
        $this->previousSwitch ??= [
            'env'    => $_ENV['UI_INERTIA'] ?? null,
            'server' => $_SERVER['UI_INERTIA'] ?? null,
            'getenv' => getenv('UI_INERTIA'),
        ];

        // Forget the value the loader wrote on a previous boot, so this one counts as externally defined.
        Env::getRepository()->clear('UI_INERTIA');
        $_ENV['UI_INERTIA'] = $_SERVER['UI_INERTIA'] = 'true';
        putenv('UI_INERTIA=true');

        parent::refreshApplication();
    }

    /**
     * @param  array<string, mixed>  $bag
     */
    private function restore(array &$bag, mixed $value): void
    {
        if (null === $value) {
            unset($bag['UI_INERTIA']);

            return;
        }

        $bag['UI_INERTIA'] = $value;
    }
}
