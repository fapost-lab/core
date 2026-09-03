<?php

declare(strict_types=1);

namespace App\Console\Installer\Steps;

use App\Console\Installer\InstallStep;
use App\Domains\Webhook\Deployment\EnvFile;
use Illuminate\Console\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

use Redis;
use RedisException;
use Throwable;

/**
 * Redis connection details, verified before they are written.
 *
 * Redis is not only the cache here — it carries every queue, the locks that
 * serialise flow sessions, and the webhook routing registry. An installation
 * that cannot reach it has no working message processing at all, so this is
 * checked during installation rather than discovered later.
 */
final class RedisStep implements InstallStep
{
    public function title(): string
    {
        return 'Redis';
    }

    public function isPending(EnvFile $env): bool
    {
        return ! $this->canConnect($this->settingsFrom($env), $error);
    }

    public function run(Command $command, EnvFile $env): bool
    {
        if (! extension_loaded('redis')) {
            $command->components->error(
                'The phpredis extension is not installed. It is the configured client '
                . '(REDIS_CLIENT=phpredis) and queues will not work without it.'
            );

            return false;
        }

        $settings = $this->settingsFrom($env);

        if ($this->canConnect($settings, $error)) {
            $command->components->twoColumnDetail(
                'Redis',
                "already reachable at {$settings['host']}:{$settings['port']}",
            );

            if (! confirm('Change the Redis settings?', default: false)) {
                return true;
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
            'REDIS_CLIENT'   => 'phpredis',
            'REDIS_HOST'     => $settings['host'],
            'REDIS_PORT'     => $settings['port'],
            'REDIS_PASSWORD' => $settings['password'],
            'REDIS_PREFIX'   => $settings['prefix'],
            // Every queue runs through Redis; the default sync driver would run
            // jobs inline in the web request, which is not a working setup here.
            'QUEUE_CONNECTION' => 'redis',
        ], 'Redis and queues');

        $command->components->twoColumnDetail('Redis', 'connected');

        return true;
    }

    /**
     * @return array{host: string, port: string, password: string, prefix: string}
     */
    private function settingsFrom(EnvFile $env): array
    {
        return [
            'host'     => $env->get('REDIS_HOST') ?? '127.0.0.1',
            'port'     => $env->get('REDIS_PORT') ?? '6379',
            'password' => $env->get('REDIS_PASSWORD') ?? '',
            'prefix'   => $env->get('REDIS_PREFIX') ?? 'fapost:',
        ];
    }

    /**
     * @param  array{host: string, port: string, password: string, prefix: string}  $current
     *
     * @return array{host: string, port: string, password: string, prefix: string}
     */
    private function ask(array $current): array
    {
        return [
            'host'     => text(label: 'Redis host', default: $current['host'], required: true),
            'port'     => text(label: 'Port', default: $current['port'], required: true),
            'password' => password(label: 'Password', hint: 'Blank if Redis requires none')
                ?: $current['password'],
            'prefix' => text(
                label: 'Key prefix',
                default: $current['prefix'],
                hint: 'The webhook gateway must resolve the same prefix, or it reads an empty registry.',
            ),
        ];
    }

    /**
     * @param  array{host: string, port: string, password: string, prefix: string}  $settings
     */
    private function canConnect(array $settings, ?string &$error = null): bool
    {
        if (! extension_loaded('redis')) {
            $error = 'phpredis extension is missing';

            return false;
        }

        try {
            $client = new Redis();
            $client->connect($settings['host'], (int) $settings['port'], 5.0);

            if ('' !== $settings['password']) {
                $client->auth($settings['password']);
            }

            $reachable = '+PONG' === $client->ping() || true === $client->ping();
            $client->close();

            return (bool) $reachable;
        } catch (RedisException|Throwable $throwable) {
            $error = $throwable->getMessage();

            return false;
        }
    }
}
