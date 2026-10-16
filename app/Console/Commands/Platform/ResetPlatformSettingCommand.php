<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Domains\Tenancy\Services\PlatformSettings;
use Fapost\Foundation\Settings\Exceptions\InvalidPlatformSettingsException;

/**
 * Removes the stored value of one platform-wide setting, so the installation default applies again.
 */
final class ResetPlatformSettingCommand extends PlatformSettingCommand
{
    /** @var string */
    protected $signature = 'platform:settings:reset {key : Setting key}';

    /** @var string */
    protected $description = 'Reset a platform-wide setting to its default';

    public function handle(PlatformSettings $settings): int
    {
        $key = (string) $this->argument('key');

        try {
            $settings->update([$key => null], $this->actor());
        } catch (InvalidPlatformSettingsException $exception) {
            foreach ($exception->violations as $violatedKey => $message) {
                $this->components->error(sprintf('"%s" %s', $violatedKey, $message));
            }

            return self::FAILURE;
        }

        $this->components->info(sprintf('"%s" is back to its default.', $key));

        return self::SUCCESS;
    }
}
