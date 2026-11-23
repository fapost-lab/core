<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * Where a question an `ask_person` node asked stands. A question leaves `Pending` once, by one conditional UPDATE, and
 * never changes again.
 */
enum FlowQuestionStatus: string
{
    /** Asked; the run waits for a press or for the timeout. */
    case Pending = 'pending';

    /** The addressee pressed one of its buttons in time. */
    case Answered = 'answered';

    /** The timeout came first. */
    case Expired = 'expired';

    /** The run no longer waits for it (it ended, was reset, moved on, or the question could not be sent). */
    case Closed = 'closed';
}
