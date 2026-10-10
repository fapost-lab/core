<?php

declare(strict_types=1);

namespace App\Domains\Messaging;

use App\Domains\Tenancy\Enums\RefusedWork;
use App\Domains\Tenancy\Services\PeriodQuota;
use App\Domains\Tenancy\Support\UsageUnitKey;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeImmutable;
use Fapost\Foundation\Messaging\OutboundMessage;
use Fapost\Foundation\Quota\Exceptions\VolumeLimitReachedException;

/**
 * Spends one unit of the tenant's outbound message volume before a message is delivered to a contact.
 *
 * {@see MessageSender} is the funnel every delivery goes through, so it calls this once per message,
 * right before the provider; the paths that deliver without it (media upload-as-send) call it first
 * with the message's own idempotency key, so the operator counts the unit once however many times it
 * is asked. A refusal is final for the attempt and surfaces as {@see VolumeLimitReachedException}.
 *
 * Edits of an already delivered message and typing indicators are not new messages and cost nothing.
 */
final readonly class OutboundVolumeGate
{
    public const string LIMIT_KEY = 'outbound_messages';

    /**
     * Metadata key a caller with a stable time sets (ISO 8601) so a retry of the message is counted in
     * the same period as the first attempt; without it the message is counted at the moment it is sent.
     */
    public const string OCCURRED_AT_METADATA = 'volume_occurred_at';

    public function __construct(
        private PeriodQuota $quota,
    ) {
    }

    /**
     * @param  DateTimeImmutable|null  $occurredAt  a time that is stable across retries when the caller has one;
     *                                            else the message's {@see self::OCCURRED_AT_METADATA}, else now,
     *                                            which costs one extra unit for a retry that crosses a period boundary
     *
     * @throws VolumeLimitReachedException when the volume for the period is used up
     */
    public function admit(OutboundMessage $message, ?DateTimeImmutable $occurredAt = null): void
    {
        if ($this->isEdit($message)) {
            return;
        }

        $this->admitKey($message->idempotencyKey, $occurredAt ?? $this->occurredAtOf($message));
    }

    /**
     * For a path that has to decide before the message is assembled; pass the key the message will carry.
     *
     * @throws VolumeLimitReachedException when the volume for the period is used up
     */
    public function admitKey(string $idempotencyKey, ?DateTimeImmutable $occurredAt = null): void
    {
        $decision = $this->quota->consume(
            self::LIMIT_KEY,
            UsageUnitKey::make('msg:', $idempotencyKey),
            $occurredAt ?? CarbonImmutable::now(),
            RefusedWork::OutboundMessage,
        );

        if ($decision->allowed) {
            return;
        }

        throw new VolumeLimitReachedException(
            self::LIMIT_KEY,
            $decision->limit,
            $decision->used,
            $decision->message ?? (null !== $decision->limit && null !== $decision->used
                ? sprintf('Outbound messages limit reached: %d of %d this period.', $decision->used, $decision->limit)
                : 'Outbound messages limit reached for this period.'),
        );
    }

    private function occurredAtOf(OutboundMessage $message): ?DateTimeImmutable
    {
        $raw = $message->metadata[self::OCCURRED_AT_METADATA] ?? null;

        if (! is_string($raw)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private function isEdit(OutboundMessage $message): bool
    {
        return 'remove_keyboard' === $message->payload->type || isset($message->metadata['edit_message_id']);
    }
}
