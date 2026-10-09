<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Exceptions\UnsupportedHostModeConfigurationException;
use Illuminate\Support\Env;
use Tests\TestCase;

/**
 * A web process in `host` mode with database sessions fails at boot; a console process does not,
 * so `config:clear` can still repair the setting.
 */
final class HostModeBootTest extends TestCase
{
    private const array VARIABLES = [
        'TENANCY_RESOLUTION'     => 'host',
        'SESSION_DRIVER'         => 'database',
        'APP_RUNNING_IN_CONSOLE' => 'false',
    ];

    /** @var array<string, string|false> */
    private array $previous = [];

    protected function tearDown(): void
    {
        foreach ($this->previous as $name => $value) {
            Env::getRepository()->clear($name);
            unset($_ENV[$name], $_SERVER[$name]);
            putenv(false === $value ? $name : "{$name}={$value}");
        }

        parent::tearDown();
    }

    public function test_a_web_process_refuses_database_sessions_in_host_mode(): void
    {
        $this->setVariables(self::VARIABLES);

        $this->expectException(UnsupportedHostModeConfigurationException::class);

        $this->refreshApplication();
    }

    public function test_a_console_process_still_boots(): void
    {
        $this->setVariables([...self::VARIABLES, 'APP_RUNNING_IN_CONSOLE' => 'true']);

        $this->refreshApplication();

        $this->assertSame('database', config('session.driver'));
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function setVariables(array $variables): void
    {
        foreach ($variables as $name => $value) {
            $this->previous[$name] ??= getenv($name);
            Env::getRepository()->clear($name);
            $_ENV[$name] = $_SERVER[$name] = $value;
            putenv("{$name}={$value}");
        }
    }
}
