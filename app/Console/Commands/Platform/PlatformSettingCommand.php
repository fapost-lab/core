<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use Illuminate\Console\Command;

/**
 * Base of the commands that change a platform-wide setting: names who ran them for the change history.
 */
abstract class PlatformSettingCommand extends Command
{
    protected function actor(): string
    {
        $user = getenv('USER');

        if (!is_string($user) || '' === $user) {
            $user = get_current_user();
        }

        return 'cli:' . ('' === $user ? 'cli' : $user);
    }
}
