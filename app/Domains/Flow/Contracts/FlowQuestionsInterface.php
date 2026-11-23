<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\DTOs\FlowQuestionDraft;
use App\Domains\Flow\Enums\FlowQuestionPressOutcome;
use App\Domains\Flow\Models\FlowQuestion;

/**
 * The questions `ask_person` nodes put to contacts, and the presses that answer them.
 *
 * A question leaves `pending` by one conditional UPDATE (`WHERE status = 'pending'`), so an answer and a timeout in the
 * same second give exactly one outcome.
 */
interface FlowQuestionsInterface
{
    /**
     * The question of this step: created on the first call, the same row (and token) on a retry of the step.
     */
    public function open(FlowQuestionDraft $draft): FlowQuestion;

    public function find(string $id): ?FlowQuestion;

    /**
     * Records the id of the message that carries the question's buttons.
     */
    public function attachMessage(string $id, string $externalMessageId): void;

    /**
     * Moves a pending question past its deadline to `expired`; false when it is no longer pending or not yet due.
     */
    public function expire(string $id): bool;

    /**
     * Moves a pending question to `closed`; false when it is no longer pending.
     */
    public function close(string $id): bool;

    /**
     * A press of a question's button by a contact at an assistant: answers the question when the press may and the
     * run still waits for it (the continuation is queued after commit), tells the presser a late press was too late,
     * and does nothing at all for a press that is not the addressee's.
     */
    public function receivePress(string $contactId, string $assistantId, string $token, int $index, string $updateId): FlowQuestionPressOutcome;
}
