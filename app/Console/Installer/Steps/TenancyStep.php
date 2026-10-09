<?php

declare(strict_types=1);

namespace App\Console\Installer\Steps;

use App\Console\Installer\InstallStep;
use App\Domains\Webhook\Deployment\EnvFile;
use Illuminate\Console\Command;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * How requests find their tenant: one tenant per installation, or one per host.
 *
 * Asked only when the file does not state a mode, so an installation that already chose one,
 * `.env.example`'s `single` included, is never re-asked. The choice is written down either way: a
 * missing line means "single" to the application, but it also means nobody decided.
 */
final class TenancyStep implements InstallStep
{
    public function title(): string
    {
        return 'Tenancy';
    }

    public function isPending(EnvFile $env): bool
    {
        return null === $env->get('TENANCY_RESOLUTION');
    }

    public function run(Command $command, EnvFile $env): bool
    {
        $mode = select(
            label: 'How is the tenant chosen?',
            options: [
                'single' => 'single - one tenant, served on every host (recommended for a self-hosted install)',
                'host'   => 'host - one tenant per <slug>.<base domain> host; needs a wildcard DNS name and certificate',
            ],
            default: 'single',
        );

        $values = ['TENANCY_RESOLUTION' => $mode];

        if ('host' === $mode) {
            // Never keep a stale value such as the example file's `localhost`: TrustHosts answers every host outside
            // the base domain with 400, so the default is the host of the public URL.
            $urlHost = parse_url($env->get('APP_URL') ?? '', PHP_URL_HOST);

            $values['TENANCY_BASE_DOMAIN'] = mb_strtolower(mb_trim(text(
                label: 'Base domain (tenants are served at <slug>.<base domain>)',
                default: is_string($urlHost) && '' !== $urlHost ? $urlHost : ($env->get('TENANCY_BASE_DOMAIN') ?? ''),
                required: true,
            )));

            // Platform pages on the base domain run a session with no tenant, so the database driver,
            // whose table lives in a tenant schema, cannot serve them.
            if (in_array($env->get('SESSION_DRIVER'), [null, 'database'], true)) {
                $values['SESSION_DRIVER'] = 'redis';
            }

            if (null !== $env->get('SESSION_DOMAIN') && 'null' !== $env->get('SESSION_DOMAIN')) {
                $command->components->warn('SESSION_DOMAIN is set; host mode needs it unset so cookies stay host-only.');
            }
        }

        $env->set($values, 'Tenancy');

        if ('host' === $mode) {
            $command->components->info('Host mode needs a wildcard DNS record and certificate: see self-hosting/docker-compose.');
        }

        return true;
    }
}
