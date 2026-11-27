<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Enums;

/**
 * Why a recipient was not sent the message, as the broadcast report shows it: a fixed category, never the text stored
 * in `broadcast_recipients.error`.
 *
 * That text is whatever the send path recorded, and for a failed send it is the sender's exception message, which can
 * carry the provider's request URL and with it a secret (a Telegram bot token is part of the URL's path). So the report
 * reads the stored text only to choose a category, and the category is all that leaves the server.
 */
enum RecipientFailureReason: string
{
    /** The run's launching job crashed; the recipients it had not reached were skipped. */
    case RunFailed = 'run_failed';

    /** The tenant's outbound message volume ran out during the run. */
    case LimitReached = 'limit_reached';

    /** The contact or the channel was deleted before the message went out. */
    case ContactMissing = 'contact_missing';

    /** The provider or the send path refused the message. */
    case DeliveryFailed = 'delivery_failed';

    /** The run stopped before this recipient was reached: a person cancelled it, or it stopped for a reason this report does not name. */
    case Stopped = 'stopped';

    /** There was no text to send: the message was empty in the contact's language and in every language it falls back to. */
    case NoText = 'no_text';

    /**
     * The text the send job records when the contact or the channel is gone ({@see \App\Domains\Broadcasting\Jobs\SendBroadcastRecipientJob}).
     */
    public const string CONTACT_MISSING_ERROR = 'contact or channel missing';

    /**
     * The text the send job records when the message is empty in every language the contact could get it in.
     */
    public const string NO_TEXT_ERROR = 'no text';

    /**
     * The category of a settled recipient, or null when there is nothing to explain (a pending or sent recipient).
     *
     * A skip with no text recorded is a recipient a cancelled run did not reach. Before the send job recorded
     * {@see self::NO_TEXT_ERROR}, a skip for an empty message had no text either: such an older row reads as "no text"
     * in a run that was not cancelled, and as "stopped" in one that was.
     *
     * A skip with a text that is not one of the run's stop reasons is the first recipient the volume limit refused: the
     * send job records the limit's own message there and stops the run with {@see BroadcastStatus::STOP_REASON_LIMIT_REACHED}.
     *
     * @param  string|null  $broadcastStopReason  the broadcast's `stop_reason`
     */
    public static function of(RecipientStatus $status, ?string $error, BroadcastStatus $broadcastStatus, ?string $broadcastStopReason): ?self
    {
        return match ($status) {
            RecipientStatus::Pending, RecipientStatus::Sent => null,
            RecipientStatus::Failed                         => self::CONTACT_MISSING_ERROR === $error ? self::ContactMissing : self::DeliveryFailed,
            RecipientStatus::Skipped                        => self::ofSkip($error, $broadcastStatus, $broadcastStopReason),
        };
    }

    private static function ofSkip(?string $error, BroadcastStatus $broadcastStatus, ?string $broadcastStopReason): self
    {
        if (null === $error || '' === $error) {
            return BroadcastStatus::Cancelled === $broadcastStatus ? self::Stopped : self::NoText;
        }

        return match ($error) {
            self::NO_TEXT_ERROR                        => self::NoText,
            BroadcastStatus::STOP_REASON_RUN_FAILED    => self::RunFailed,
            BroadcastStatus::STOP_REASON_LIMIT_REACHED => self::LimitReached,
            default                                    => BroadcastStatus::STOP_REASON_LIMIT_REACHED === $broadcastStopReason ? self::LimitReached : self::Stopped,
        };
    }
}
