<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Webhook\Deployment\GatewayArtifacts;
use App\Domains\Webhook\Deployment\GatewayPlan;
use App\Domains\Webhook\Deployment\LogTarget;
use App\Domains\Webhook\Deployment\RunMode;
use Tests\TestCase;

/**
 * These files are copied into /etc and run as services, so a defect here surfaces
 * as a host that will not start rather than as a failing request.
 */
final class GatewayArtifactsTest extends TestCase
{
    /**
     * SIGHUP is how logrotate tells the gateway to reopen its file and how a spec
     * republish takes effect. A unit without ExecReload leaves both to a restart,
     * which drops in-flight deliveries the provider will not resend.
     */
    public function test_systemd_unit_wires_reload_to_sighup(): void
    {
        $unit = $this->artifacts($this->plan())->systemdUnit();

        $this->assertStringContainsString('ExecReload=/bin/kill -HUP $MAINPID', $unit);
        $this->assertStringContainsString('KillSignal=SIGTERM', $unit);
        $this->assertStringContainsString('TimeoutStopSec=', $unit);
    }

    public function test_systemd_unit_runs_unprivileged_and_confined(): void
    {
        $unit = $this->artifacts($this->plan(serviceUser: 'fapost'))->systemdUnit();

        $this->assertStringContainsString('User=fapost', $unit);
        $this->assertStringContainsString('NoNewPrivileges=true', $unit);
        $this->assertStringContainsString('ProtectSystem=strict', $unit);
    }

    /**
     * ProtectSystem=strict mounts the filesystem read-only, so a log file needs an
     * explicit exception. Without it the service starts and dies on its first write.
     */
    public function test_file_logging_grants_write_access_to_the_log_directory(): void
    {
        $unit = $this->artifacts($this->plan(
            logTarget: LogTarget::File,
            logPath: '/var/log/fapost/gateway.log',
        ))->systemdUnit();

        $this->assertStringContainsString('ReadWritePaths=/var/log/fapost', $unit);
    }

    public function test_stdout_logging_needs_no_write_exception(): void
    {
        $unit = $this->artifacts($this->plan())->systemdUnit();

        $this->assertStringNotContainsString('ReadWritePaths=', $unit);
    }

    public function test_logrotate_is_generated_only_for_file_logging(): void
    {
        $this->assertArrayNotHasKey('fapost-gateway.logrotate', $this->artifacts($this->plan())->all());

        $withFile = $this->artifacts($this->plan(logTarget: LogTarget::File))->all();

        $this->assertArrayHasKey('fapost-gateway.logrotate', $withFile);
        $this->assertStringContainsString('systemctl reload fapost-gateway', $withFile['fapost-gateway.logrotate']);
    }

    /**
     * Under Docker there is no systemctl, so the rotation hook has to signal the
     * process directly or rotation silently stops working.
     */
    public function test_logrotate_signals_directly_when_not_under_systemd(): void
    {
        $config = $this->artifacts($this->plan(
            runMode: RunMode::Manual,
            logTarget: LogTarget::File,
        ))->logrotateConfig();

        $this->assertStringContainsString('pkill -HUP', $config);
        $this->assertStringNotContainsString('systemctl', $config);
    }

    public function test_each_run_mode_produces_its_own_supervisor_file(): void
    {
        $expected = [
            RunMode::Systemd->value => 'fapost-gateway.service',
            RunMode::Docker->value  => 'docker-compose.gateway.yml',
            RunMode::Manual->value  => 'run-gateway.sh',
        ];

        foreach (RunMode::cases() as $mode) {
            $files = $this->artifacts($this->plan(runMode: $mode))->all();

            $this->assertArrayHasKey($expected[$mode->value], $files);
            $this->assertArrayHasKey('README.md', $files, 'Every deployment needs its own instructions.');
        }
    }

    /**
     * Configuration is injected at runtime so one image is promoted unchanged
     * between environments. A compose file that mounted .env would break that.
     */
    public function test_compose_injects_configuration_rather_than_mounting_env(): void
    {
        $compose = $this->artifacts($this->plan(runMode: RunMode::Docker))->composeService();

        $this->assertStringContainsString('GATEWAY_UPSTREAM_URL:', $compose);
        $this->assertStringNotContainsString('env_file', $compose);
        $this->assertStringContainsString('8080:8080', $compose);
    }

    public function test_environment_reuses_the_applications_redis_settings(): void
    {
        $environment = $this->plan()->environment();

        $this->assertSame('gateway', $environment['WEBHOOK_INGRESS_DRIVER']);
        $this->assertSame('https://webhook.example.com', $environment['WEBHOOK_GATEWAY_URL']);

        foreach (array_keys($environment) as $key) {
            $this->assertStringStartsNotWith(
                'REDIS_',
                $key,
                'Redis settings must not be duplicated; the gateway reads the application\'s.',
            );
        }
    }

    public function test_trusted_proxies_are_only_written_when_configured(): void
    {
        $this->assertArrayNotHasKey('GATEWAY_TRUSTED_PROXIES', $this->plan()->environment());

        $withProxy = $this->plan(trustedProxies: ['127.0.0.1', '10.0.0.5'])->environment();

        $this->assertSame('127.0.0.1,10.0.0.5', $withProxy['GATEWAY_TRUSTED_PROXIES']);
    }

    /**
     * @param  list<string>  $trustedProxies
     */
    private function plan(
        RunMode $runMode = RunMode::Systemd,
        LogTarget $logTarget = LogTarget::Stdout,
        string $logPath = '',
        array $trustedProxies = [],
        string $serviceUser = 'www-data',
    ): GatewayPlan {
        return new GatewayPlan(
            runMode: $runMode,
            publicUrl: 'https://webhook.example.com',
            listenAddr: '127.0.0.1:8080',
            upstreamUrl: 'https://app.example.com',
            logTarget: $logTarget,
            logPath: $logPath,
            logLevel: 'info',
            trustedProxies: $trustedProxies,
            ratePerSecond: 30.0,
            rateBurst: 60,
            installDir: '/usr/local/bin',
            serviceUser: $serviceUser,
        );
    }

    private function artifacts(GatewayPlan $plan): GatewayArtifacts
    {
        return new GatewayArtifacts($plan);
    }
}
