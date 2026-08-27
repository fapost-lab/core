<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Deployment;

/**
 * How the gateway process is supervised on the target host.
 */
enum RunMode: string
{
    /** A systemd unit on the host, built from source during installation. */
    case Systemd = 'systemd';

    /** A container alongside the rest of the stack. */
    case Docker = 'docker';

    /** Started by hand or by a supervisor the operator manages themselves. */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Systemd => 'systemd service on this host',
            self::Docker  => 'Docker container',
            self::Manual  => 'run it myself (no supervisor files)',
        };
    }

    /**
     * Whether installation should produce a binary on this machine.
     *
     * Docker builds inside its own image, so the host needs no Go toolchain.
     */
    public function buildsLocally(): bool
    {
        return self::Docker !== $this;
    }
}
