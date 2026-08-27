<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Deployment;

/**
 * Where the gateway writes its log records.
 */
enum LogTarget: string
{
    /**
     * Standard output, leaving retention to the supervisor.
     *
     * The right default under systemd, Docker and Kubernetes alike: rotation
     * stays outside the process, where it is already solved.
     */
    case Stdout = 'stdout';

    /** A file the process owns, reopened on SIGHUP so logrotate can rotate it. */
    case File = 'file';

    public function label(): string
    {
        return match ($this) {
            self::Stdout => 'stdout (journald / docker logs handle retention)',
            self::File   => 'a log file rotated by logrotate',
        };
    }
}
