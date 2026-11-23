<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * What a press of a question's button did.
 */
enum FlowQuestionPressOutcome: string
{
    /** It answered the question; the run is continued by a queued job. */
    case Accepted = 'accepted';

    /** The question was already decided, or its run no longer waits: the presser got the late-answer text. */
    case Late = 'late';

    /** Not a press that may answer: unknown token, another contact or assistant, no such button. Nothing happened. */
    case NotOurs = 'not_ours';
}
