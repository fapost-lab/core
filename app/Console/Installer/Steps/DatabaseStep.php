<?php

declare(strict_types=1);

namespace App\Console\Installer\Steps;

use App\Console\Installer\InstallStep;
use App\Domains\Webhook\Deployment\EnvFile;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

use PDO;
use PDOException;
use Throwable;

/**
 * PostgreSQL connection details, verified before they are written.
 *
 * Verification happens here rather than at the first migration because a typo in
 * a host name should be a question repeated, not a stack trace at the end of a
 * long install. The check also covers the privilege the application needs and
 * managed databases usually withhold.
 */
final class DatabaseStep implements InstallStep
{
    public function title(): string
    {
        return 'Database';
    }

    public function isPending(EnvFile $env): bool
    {
        return ! $this->canConnect($this->settingsFrom($env), $error);
    }

    public function run(Command $command, EnvFile $env): bool
    {
        $settings = $this->settingsFrom($env);

        if ($this->canConnect($settings, $error)) {
            $command->components->twoColumnDetail(
                'PostgreSQL',
                "already reachable at {$settings['host']}:{$settings['port']}",
            );

            if (! confirm('Change the database settings?', default: false)) {
                return $this->verifyPrivileges($command, $settings);
            }
        }

        while (true) {
            $settings = $this->ask($settings);

            if ($this->canConnect($settings, $error)) {
                break;
            }

            $command->components->error("Could not connect: {$error}");

            if (! confirm('Try different settings?', default: true)) {
                return false;
            }
        }

        $env->set([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST'       => $settings['host'],
            'DB_PORT'       => $settings['port'],
            'DB_DATABASE'   => $settings['database'],
            'DB_USERNAME'   => $settings['username'],
            'DB_PASSWORD'   => $settings['password'],
        ], 'Database');

        $command->components->twoColumnDetail('PostgreSQL', 'connected');

        return $this->verifyPrivileges($command, $settings);
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    private function settingsFrom(EnvFile $env): array
    {
        return [
            'host'     => $env->get('DB_HOST') ?? '127.0.0.1',
            'port'     => $env->get('DB_PORT') ?? '5432',
            'database' => $env->get('DB_DATABASE') ?? 'fapost',
            'username' => $env->get('DB_USERNAME') ?? 'fapost',
            'password' => $env->get('DB_PASSWORD') ?? '',
        ];
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $current
     *
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    private function ask(array $current): array
    {
        return [
            'host'     => text(label: 'Database host', default: $current['host'], required: true),
            'port'     => text(label: 'Port', default: $current['port'], required: true),
            'database' => text(label: 'Database name', default: $current['database'], required: true),
            'username' => text(label: 'Username', default: $current['username'], required: true),
            'password' => password(label: 'Password', hint: 'Leave blank to keep the current value')
                ?: $current['password'],
        ];
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $settings
     */
    private function canConnect(array $settings, ?string &$error = null): bool
    {
        try {
            $this->connect($settings);

            return true;
        } catch (Throwable $throwable) {
            $error = $throwable->getMessage();

            return false;
        }
    }

    /**
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $settings
     *
     * @throws PDOException
     */
    private function connect(array $settings): PDO
    {
        return new PDO(
            "pgsql:host={$settings['host']};port={$settings['port']};dbname={$settings['database']}",
            $settings['username'],
            $settings['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
        );
    }

    /**
     * Confirm the user may create schemas.
     *
     * Tenant isolation is schema-per-tenant and provisioning issues CREATE SCHEMA
     * at runtime. Without this grant the install completes and then fails on the
     * first tenant, with an error that names neither the grant nor the fix.
     *
     * @param  array{host: string, port: string, database: string, username: string, password: string}  $settings
     */
    private function verifyPrivileges(Command $command, array $settings): bool
    {
        try {
            $connection = $this->connect($settings);

            $allowed = (bool) $connection
                ->query('SELECT has_database_privilege(current_user, current_database(), \'CREATE\')')
                ->fetchColumn();
        } catch (Throwable $throwable) {
            $command->components->warn('Could not check privileges: ' . $throwable->getMessage());

            return true;
        }

        if ($allowed) {
            $command->components->twoColumnDetail('CREATE privilege', 'granted');

            return true;
        }

        $command->components->error(sprintf(
            'User "%s" cannot create schemas in "%s". Tenant provisioning needs it: '
            . 'GRANT CREATE ON DATABASE %s TO %s;',
            $settings['username'],
            $settings['database'],
            $settings['database'],
            $settings['username'],
        ));

        return confirm('Continue anyway? Tenant provisioning will fail without it.', default: false);
    }
}
