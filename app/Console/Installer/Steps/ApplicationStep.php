<?php

declare(strict_types=1);

namespace App\Console\Installer\Steps;

use App\Console\Installer\InstallStep;
use App\Domains\Webhook\Deployment\EnvFile;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Application identity: name, public URL, environment, and the encryption key.
 */
final class ApplicationStep implements InstallStep
{
    public function title(): string
    {
        return 'Application';
    }

    public function isPending(EnvFile $env): bool
    {
        return null === $env->get('APP_KEY') || null === $env->get('APP_URL');
    }

    public function run(Command $command, EnvFile $env): bool
    {
        $values = [
            'APP_NAME' => text(
                label: 'Application name',
                default: $env->get('APP_NAME') ?? 'FaPost',
                required: true,
            ),
            'APP_URL' => mb_rtrim(text(
                label: 'Public URL of the admin panel',
                placeholder: 'https://fapost.example.com',
                default: $env->get('APP_URL') ?? '',
                required: true,
                validate: static fn (string $value): ?string => str_starts_with($value, 'http://')
                    || str_starts_with($value, 'https://')
                        ? null
                        : 'Include the scheme, e.g. https://fapost.example.com',
            ), '/'),
            'APP_ENV' => select(
                label: 'Environment',
                options: [
                    'production' => 'production (recommended for a real install)',
                    'local'      => 'local (development tooling enabled)',
                ],
                default: $env->get('APP_ENV') ?? 'production',
            ),
        ];

        // Debug output leaks paths, configuration and stack traces to anyone who
        // can trigger an error, so it follows the environment rather than being
        // one more thing to remember.
        $values['APP_DEBUG'] = 'production' === $values['APP_ENV'] ? 'false' : 'true';

        $env->set($values, 'Application');

        return $this->ensureKey($command, $env);
    }

    /**
     * Generate the encryption key when absent.
     *
     * Regenerating an existing key would invalidate every encrypted column —
     * channel tokens and secrets among them — so an existing one is never touched.
     */
    private function ensureKey(Command $command, EnvFile $env): bool
    {
        if (null !== $env->get('APP_KEY')) {
            $command->components->twoColumnDetail('Encryption key', 'already set, left alone');

            return true;
        }

        $key = 'base64:' . base64_encode(Encrypter::generateKey(config('app.cipher', 'AES-256-CBC')));

        $env->set(['APP_KEY' => $key]);

        // The running process still holds the old (empty) key in its config, so
        // anything later in this run that encrypts would use it.
        Artisan::call('config:clear');

        $command->components->twoColumnDetail('Encryption key', 'generated');

        return true;
    }
}
