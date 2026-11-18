<?php

declare(strict_types=1);

namespace App\Domains\Flow\DTOs;

use App\Domains\Flow\Enums\FlowRunKind;
use InvalidArgumentException;

/**
 * Where a new flow session comes from, as {@see \App\Domains\Flow\Contracts\FlowEngineInterface::start()} records it:
 * the run kind, the trigger that fired it, and — for a run without a contact — the assistant it belongs to, if any.
 *
 * The assistant of a contactless run is only ever the one named here: the engine never takes the ambient current
 * assistant for it, so a run cannot be attached to whichever assistant a job happened to resolve. A run with a
 * contact keeps the current assistant, as every conversation does, and ignores {@see $assistantId}.
 *
 * The fire key is what fired the trigger — a schedule slot, an event id, a webhook delivery id. It exists only inside
 * a trigger: `(trigger_id, fire_key)` is unique, so a run is started once per fire.
 */
final readonly class FlowRunOrigin
{
    /** The length of `flow_sessions.fire_key`; a trigger hashes a longer id of its own before it starts a run. */
    public const int MAX_FIRE_KEY_LENGTH = 191;

    public function __construct(
        public FlowRunKind $kind,
        public ?string $triggerId = null,
        public ?string $assistantId = null,
        public ?string $fireKey = null,
    ) {
        if (null !== $fireKey && ('' === $fireKey || null === $triggerId || '' === $triggerId)) {
            throw new InvalidArgumentException('A fire key needs the trigger it belongs to, and cannot be empty.');
        }

        if (null !== $fireKey && mb_strlen($fireKey) > self::MAX_FIRE_KEY_LENGTH) {
            throw new InvalidArgumentException(sprintf('A fire key is at most %d characters.', self::MAX_FIRE_KEY_LENGTH));
        }
    }

    public static function conversation(?string $triggerId = null): self
    {
        return new self(FlowRunKind::Conversation, $triggerId);
    }

    public static function automation(?string $triggerId = null, ?string $assistantId = null, ?string $fireKey = null): self
    {
        return new self(FlowRunKind::Automation, $triggerId, $assistantId, $fireKey);
    }
}
