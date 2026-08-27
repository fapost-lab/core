<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Deployment;

/**
 * Renders the supervisor and rotation files for a chosen deployment.
 *
 * Generated rather than shipped as fixed samples because every one of them
 * embeds decisions the operator just made — paths, user, listen address, whether
 * a log file exists at all. A static example would have to be edited by hand in
 * exactly the places that are easiest to get wrong.
 *
 * Nothing here writes outside the project: files land in a directory for the
 * operator to review and install, because dropping units into /etc during an
 * install is a surprise nobody wants from an installer.
 */
final readonly class GatewayArtifacts
{
    public function __construct(
        private GatewayPlan $plan,
    ) {
    }

    /**
     * All artifacts for the chosen run mode, keyed by file name.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $files = match ($this->plan->runMode) {
            RunMode::Systemd => ['fapost-gateway.service' => $this->systemdUnit()],
            RunMode::Docker  => ['docker-compose.gateway.yml' => $this->composeService()],
            RunMode::Manual  => ['run-gateway.sh' => $this->runScript()],
        };

        if ($this->plan->needsLogrotate()) {
            $files['fapost-gateway.logrotate'] = $this->logrotateConfig();
        }

        $files['README.md'] = $this->readme();

        return $files;
    }

    public function systemdUnit(): string
    {
        $binary  = $this->plan->installDir . '/gateway';
        $envFile = base_path('.env');

        return <<<UNIT
            [Unit]
            Description=FAPost webhook ingress gateway
            Documentation=https://github.com/fapost/fapost-core
            After=network-online.target redis.service
            Wants=network-online.target

            [Service]
            Type=simple
            User={$this->plan->serviceUser}
            Group={$this->plan->serviceUser}

            # Reads the same .env as the application, so Redis settings are configured once.
            ExecStart={$binary} --env {$envFile}

            # SIGHUP reopens the log file for logrotate and drops cached ingress
            # specs, so a republish takes effect without dropping connections.
            ExecReload=/bin/kill -HUP \$MAINPID

            Restart=always
            RestartSec=2

            # Deliveries already answered with 200 are in flight and will not be
            # resent by the provider, so the process is given time to drain them.
            KillSignal=SIGTERM
            TimeoutStopSec=30

            # The gateway needs no privileges: it opens one socket, reads .env and
            # talks to Redis. Everything else is taken away.
            NoNewPrivileges=true
            PrivateTmp=true
            PrivateDevices=true
            ProtectSystem=strict
            ProtectHome=true
            ProtectKernelTunables=true
            ProtectKernelModules=true
            ProtectControlGroups=true
            RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
            RestrictNamespaces=true
            LockPersonality=true
            MemoryDenyWriteExecute=true
            {$this->systemdWritePaths()}

            [Install]
            WantedBy=multi-user.target
            UNIT;
    }

    public function composeService(): string
    {
        $port = $this->exposedPort();

        return <<<YAML
            # Merge into your stack:
            #   docker compose -f docker-compose.yml -f docker-compose.gateway.yml up -d
            services:
              gateway:
                build:
                  context: ./gateway
                  args:
                    VERSION: \${GATEWAY_VERSION:-dev}
                restart: unless-stopped
                ports:
                  - "{$port}:{$port}"
                environment:
                  # Configuration is injected, never baked into the image, so the same
                  # image is promoted unchanged from staging to production.
                  GATEWAY_ADDR: "{$this->plan->listenAddr}"
                  GATEWAY_UPSTREAM_URL: "{$this->plan->upstreamUrl}"
                  GATEWAY_LOG_LEVEL: "{$this->plan->logLevel}"
                  GATEWAY_LOG_FORMAT: "json"
                  GATEWAY_LOG_DESTINATION: "stdout"
                  GATEWAY_RATE_PER_SECOND: "{$this->plan->ratePerSecond}"
                  GATEWAY_RATE_BURST: "{$this->plan->rateBurst}"
                  REDIS_HOST: "\${REDIS_HOST:-redis}"
                  REDIS_PORT: "\${REDIS_PORT:-6379}"
                  REDIS_PASSWORD: "\${REDIS_PASSWORD:-}"
                  REDIS_PREFIX: "\${REDIS_PREFIX:-}"
                  REDIS_DB: "\${REDIS_DB:-0}"
                healthcheck:
                  # The binary is its own health probe: a scratch image has no curl.
                  test: ["CMD", "/gateway", "--version"]
                  interval: 30s
                  timeout: 3s
                  retries: 3
            YAML;
    }

    public function logrotateConfig(): string
    {
        $reload = RunMode::Systemd === $this->plan->runMode
            ? '        systemctl reload fapost-gateway'
            : '        pkill -HUP -f "gateway --env" || true';

        return <<<CONF
            {$this->plan->logPath} {
                daily
                rotate 14
                compress
                delaycompress
                missingok
                notifempty
                create 0640 {$this->plan->serviceUser} {$this->plan->serviceUser}
                sharedscripts
                postrotate
                    # Without the signal the process keeps writing into the rotated
                    # file and every later entry lands where nobody looks.
            {$reload}
                endscript
            }
            CONF;
    }

    public function runScript(): string
    {
        $envFile = base_path('.env');
        $binary  = $this->plan->installDir . '/gateway';

        return <<<SH
            #!/bin/sh
            # Minimal launcher. Point a supervisor at this, or run it directly.
            set -eu

            exec "{$binary}" --env "{$envFile}"
            SH;
    }

    public function readme(): string
    {
        return match ($this->plan->runMode) {
            RunMode::Systemd => $this->systemdReadme(),
            RunMode::Docker  => $this->dockerReadme(),
            RunMode::Manual  => $this->manualReadme(),
        };
    }

    private function systemdReadme(): string
    {
        $rotate = $this->plan->needsLogrotate()
            ? "\n3. Install log rotation:\n\n       sudo cp fapost-gateway.logrotate /etc/logrotate.d/fapost-gateway\n"
            : '';

        return <<<MD
            # Gateway deployment (systemd)

            Generated by `php artisan gateway:install`. Review before installing.

            1. Install the unit:

                   sudo cp fapost-gateway.service /etc/systemd/system/
                   sudo systemctl daemon-reload

            2. Start it:

                   sudo systemctl enable --now fapost-gateway
                   systemctl status fapost-gateway
            {$rotate}
            ## Verify

                   php artisan gateway:doctor

            ## Routing traffic here

            Point providers at `{$this->plan->publicUrl}` by re-registering channels:

                   php artisan ops:ingress-migrate --apply

            Existing channels keep working on the application's own URL until they
            are migrated — both ingress paths stay valid, so there is no cutover.

            ## TLS

            The gateway speaks plain HTTP and expects a terminator in front
            (Traefik, Caddy, nginx). Whatever you use must forward the request body
            unmodified: signatures are computed over the exact bytes the provider
            sent, and any rewriting breaks verification.
            MD;
    }

    private function dockerReadme(): string
    {
        return <<<MD
            # Gateway deployment (Docker)

            Generated by `php artisan gateway:install`. Review before deploying.

            1. Bring it up alongside your stack:

                   docker compose -f docker-compose.yml -f docker-compose.gateway.yml up -d --build

            2. Check it:

                   docker compose logs -f gateway
                   php artisan gateway:doctor

            ## Notes

            The image is built from `gateway/Dockerfile` and contains only the
            binary — no shell, no package manager. Configuration is injected as
            environment variables, so the same image moves unchanged between
            environments.

            Logs go to stdout; retention is the container runtime's job.

            ## Routing traffic here

            Point providers at `{$this->plan->publicUrl}`:

                   php artisan ops:ingress-migrate --apply
            MD;
    }

    private function manualReadme(): string
    {
        return <<<MD
            # Gateway deployment (manual)

            Generated by `php artisan gateway:install`.

            Start it with:

                   ./run-gateway.sh

            Send `SIGHUP` to reopen the log file and refresh cached ingress specs;
            `SIGTERM` shuts it down gracefully, draining requests already accepted.

            Verify the wiring with:

                   php artisan gateway:doctor

            ## Routing traffic here

            Point providers at `{$this->plan->publicUrl}`:

                   php artisan ops:ingress-migrate --apply
            MD;
    }

    /**
     * ProtectSystem=strict makes the filesystem read-only, so a log file needs an
     * explicit exception. Without one the service starts and then fails on its
     * first write.
     */
    private function systemdWritePaths(): string
    {
        if (! $this->plan->needsLogrotate()) {
            return '';
        }

        return 'ReadWritePaths=' . dirname($this->plan->logPath);
    }

    private function exposedPort(): string
    {
        $addr = $this->plan->listenAddr;
        $port = mb_substr($addr, (int) mb_strrpos($addr, ':') + 1);

        return '' === $port ? '8080' : $port;
    }
}
