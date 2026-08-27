<?php

declare(strict_types=1);

namespace App\Console\Installer;

use App\Domains\Webhook\Deployment\EnvFile;
use Illuminate\Console\Command;

/**
 * One stage of the installation wizard.
 *
 * Steps are separate objects so the wizard is a list rather than a procedure:
 * adding storage, mail or another integration means adding a class, not editing
 * a growing method. It also lets each step be skipped independently when its
 * settings are already valid, which is what makes re-running the installer safe.
 */
interface InstallStep
{
    /**
     * Short label shown as the step heading.
     */
    public function title(): string;

    /**
     * Whether this step still has work to do.
     *
     * Returning false on a re-run is how the installer stays idempotent: an
     * installation that is already configured and reachable is left alone rather
     * than re-asked and re-written.
     */
    public function isPending(EnvFile $env): bool;

    /**
     * Collect answers, verify them, and persist them to the environment file.
     *
     * Returns false when the step could not be completed — a database that never
     * accepted a connection, say. The wizard stops there rather than continuing
     * into steps that depend on it.
     */
    public function run(Command $command, EnvFile $env): bool;
}
