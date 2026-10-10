<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Enums;

/**
 * What became of one operator submission in the inbox.
 */
enum ReplyOutcome: string
{
    /** The message went out. */
    case Sent = 'sent';

    /** The same submission was already sent, or is being sent by another request: nothing went out this time. */
    case Duplicate = 'duplicate';

    /**
     * Whether the message went out is not known: the same submission is still in flight elsewhere, or an earlier attempt
     * failed after the provider may have taken it. The operator checks the transcript before sending it again.
     */
    case Unconfirmed = 'unconfirmed';

    /** The provider did not take the message; the submission may be retried. */
    case Failed = 'failed';
}
