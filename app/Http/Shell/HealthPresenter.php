<?php

declare(strict_types=1);

namespace App\Http\Shell;

use App\Domains\Health\Enums\HealthState;
use App\Domains\Health\Probes\GatewayProbe;
use App\Domains\Health\Probes\ReverbProbe;
use App\Domains\Health\ValueObjects\HealthReport;
use App\Domains\Health\ValueObjects\ProbeResult;

/**
 * Turns the installation's {@see HealthReport} into the props of the health page: one card per service, each with the
 * state the probe established, its reason and the few figures worth drawing.
 *
 * It narrows nothing by audience (the page exists in `single` mode only); a consumer that shows a tenant's slice
 * filters the report before it gets here. Channel names are not passed on, only their number: a name can carry a
 * tenant id and the page has no use for it. A service the report does not mention reads as "unknown", never as healthy.
 */
final class HealthPresenter
{
    /**
     * @return array{reverb: array<string, mixed>, gateway: array<string, mixed>}
     */
    public function page(HealthReport $report): array
    {
        return [
            'reverb'  => $this->reverb($report->get(ReverbProbe::KEY)),
            'gateway' => $this->gateway($report->get(GatewayProbe::KEY)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reverb(?ProbeResult $result): array
    {
        return [
            ...$this->base(ReverbProbe::KEY, $result),
            'address'     => $this->text($result, 'address'),
            'connections' => $this->count($result, 'connections'),
            'channels'    => $this->count($result, 'channels'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function gateway(?ProbeResult $result): array
    {
        $specs = $result?->details['specs'] ?? null;
        $drift = $result?->details['drift'] ?? null;

        return [
            ...$this->base(GatewayProbe::KEY, $result),
            'url'   => $this->scrubbed($this->text($result, 'url')),
            'specs' => is_array($specs)
                ? array_values(array_map(
                    static fn (string $platform, mixed $status): array => ['platform' => $platform, 'status' => (string) $status],
                    array_keys($specs),
                    $specs,
                ))
                : null,
            'drift' => is_array($drift)
                ? [
                    'total'     => (int) array_sum($drift),
                    'platforms' => array_values(array_map(
                        static fn (string $platform, mixed $count): array => ['platform' => $platform, 'count' => (int) $count],
                        array_keys($drift),
                        $drift,
                    )),
                ]
                : null,
        ];
    }

    /**
     * @return array{key: string, state: string, reason: string|null, checkedAt: string|null}
     */
    private function base(string $key, ?ProbeResult $result): array
    {
        if (null === $result) {
            return [
                'key'       => $key,
                'state'     => HealthState::Unknown->value,
                'reason'    => null,
                'checkedAt' => null,
            ];
        }

        return [
            'key'       => $key,
            'state'     => $result->state->value,
            'reason'    => $this->scrubbed($result->reason),
            'checkedAt' => $result->checkedAt->toIso8601String(),
        ];
    }

    /**
     * The text with every URL in it cut down to scheme, host, port and path: a gateway address configured with a
     * password or a token must not reach the browser through the probe's reason or its details.
     */
    private function scrubbed(?string $text): ?string
    {
        if (null === $text) {
            return null;
        }

        return preg_replace_callback(
            '~https?://[^\s"\'<>]+~i',
            static function (array $match): string {
                $parts = parse_url(mb_rtrim($match[0], '.,;:)'));

                if (false === $parts || ! isset($parts['scheme'], $parts['host'])) {
                    return $match[0];
                }

                $trailing = mb_substr($match[0], mb_strlen(mb_rtrim($match[0], '.,;:)')));

                return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '') . $trailing;
            },
            $text,
        );
    }

    private function count(?ProbeResult $result, string $detail): ?int
    {
        $value = $result?->details[$detail] ?? null;

        return is_int($value) ? $value : null;
    }

    private function text(?ProbeResult $result, string $detail): ?string
    {
        $value = $result?->details[$detail] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }
}
