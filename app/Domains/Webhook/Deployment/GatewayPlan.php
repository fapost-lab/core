<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Deployment;

/**
 * The operator's answers, resolved into the settings every generated artifact
 * derives from.
 *
 * A value object rather than an array so the installer's questions and the files
 * it writes cannot drift apart: adding a deployment option means adding a field
 * here, and every generator that needs it stops compiling until it is handled.
 */
final readonly class GatewayPlan
{
    /**
     * @param  string        $publicUrl      Public base URL providers will deliver to.
     * @param  string        $listenAddr     Address the gateway binds, e.g. ":8080".
     * @param  string        $upstreamUrl    The application's own ingress, used for proxy fallback.
     * @param  list<string>  $trustedProxies Addresses whose X-Forwarded-For may be believed.
     */
    public function __construct(
        public RunMode $runMode,
        public string $publicUrl,
        public string $listenAddr,
        public string $upstreamUrl,
        public LogTarget $logTarget,
        public string $logPath,
        public string $logLevel,
        public array $trustedProxies,
        public float $ratePerSecond,
        public int $rateBurst,
        public string $installDir,
        public string $serviceUser,
    ) {
    }

    /**
     * Environment values the gateway and the application both read.
     *
     * Redis settings are deliberately absent: the gateway reads the same
     * REDIS_* variables the application already uses, so there is nothing to
     * duplicate and nothing that can fall out of step.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        $values = [
            'WEBHOOK_INGRESS_DRIVER'  => 'gateway',
            'WEBHOOK_GATEWAY_URL'     => $this->publicUrl,
            'GATEWAY_ADDR'            => $this->listenAddr,
            'GATEWAY_UPSTREAM_URL'    => $this->upstreamUrl,
            'GATEWAY_LOG_LEVEL'       => $this->logLevel,
            'GATEWAY_LOG_FORMAT'      => 'json',
            'GATEWAY_LOG_DESTINATION' => $this->logTarget->value,
            'GATEWAY_RATE_PER_SECOND' => (string) $this->ratePerSecond,
            'GATEWAY_RATE_BURST'      => (string) $this->rateBurst,
        ];

        if (LogTarget::File === $this->logTarget) {
            $values['GATEWAY_LOG_PATH'] = $this->logPath;
        }

        if ([] !== $this->trustedProxies) {
            $values['GATEWAY_TRUSTED_PROXIES'] = implode(',', $this->trustedProxies);
        }

        return $values;
    }

    /**
     * Whether an external rotator needs to be told how to signal the process.
     */
    public function needsLogrotate(): bool
    {
        return LogTarget::File === $this->logTarget;
    }
}
