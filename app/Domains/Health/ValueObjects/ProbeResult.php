<?php

declare(strict_types=1);

namespace App\Domains\Health\ValueObjects;

use App\Domains\Health\Enums\HealthState;
use Carbon\CarbonImmutable;

/**
 * One probe's answer: a state, the reason for it in a sentence, and whatever the probe measured on the way.
 *
 * Installation-wide and unfiltered. A consumer that shows it to a tenant narrows it first; `details` may carry
 * channel names that contain a tenant id. It never carries a credential.
 */
final readonly class ProbeResult
{
    /**
     * @param array<string, mixed> $details
     */
    private function __construct(
        public string $probe,
        public HealthState $state,
        public string $reason,
        public array $details,
        public CarbonImmutable $checkedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function up(string $probe, string $reason, array $details = []): self
    {
        return new self($probe, HealthState::Up, $reason, $details, CarbonImmutable::now());
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function down(string $probe, string $reason, array $details = []): self
    {
        return new self($probe, HealthState::Down, $reason, $details, CarbonImmutable::now());
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function unknown(string $probe, string $reason, array $details = []): self
    {
        return new self($probe, HealthState::Unknown, $reason, $details, CarbonImmutable::now());
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function disabled(string $probe, string $reason, array $details = []): self
    {
        return new self($probe, HealthState::Disabled, $reason, $details, CarbonImmutable::now());
    }

    /**
     * @param array{probe: string, state: string, reason: string, details: array<string, mixed>, checked_at: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['probe'],
            HealthState::from($data['state']),
            $data['reason'],
            $data['details'],
            CarbonImmutable::parse($data['checked_at']),
        );
    }

    /**
     * The same answer with more measurements beside it.
     *
     * @param array<string, mixed> $more
     */
    public function withDetails(array $more): self
    {
        return new self($this->probe, $this->state, $this->reason, [...$this->details, ...$more], $this->checkedAt);
    }

    /**
     * @return array{probe: string, state: string, reason: string, details: array<string, mixed>, checked_at: string}
     */
    public function toArray(): array
    {
        return [
            'probe'      => $this->probe,
            'state'      => $this->state->value,
            'reason'     => $this->reason,
            'details'    => $this->details,
            'checked_at' => $this->checkedAt->toIso8601String(),
        ];
    }
}
