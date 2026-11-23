<?php

declare(strict_types=1);

namespace App\Domains\Flow\Orchestration;

use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\FlowQuestionsInterface;
use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Contracts\InlineKeyboardEditorInterface;
use App\Domains\Flow\Enums\FlowQuestionStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Handlers\AskPersonNodeHandler;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\SegmentHandOff;

/**
 * Continues a run whose `ask_person` question was answered — the receiving side of
 * {@see \App\Domains\Flow\Contracts\FlowQuestionContinuationSchedulerInterface}.
 *
 * Under the run's own lock ({@see LockScope::ofSession()}: the contact's, or the trigger's for a contactless run), it
 * re-reads the session and wakes it only while it is still `paused` on the question's node with the question's id in
 * the node's marker; the node then reads the answer from the question. Anything else is a stale continuation and a
 * no-op, so a repeated job never moves the run twice. The answered question's buttons come off afterwards (best
 * effort).
 */
final readonly class FlowQuestionResumer
{
    public function __construct(
        private FlowQuestionsInterface $questions,
        private FlowSessionRepositoryInterface $sessions,
        private FlowExecutionGuardInterface $guard,
        private FlowEngineInterface $engine,
        private SegmentHandOff $handOff,
        private InlineKeyboardEditorInterface $keyboardEditor,
    ) {
    }

    public function continue(string $questionId): void
    {
        $question = $this->questions->find($questionId);

        if (null === $question || FlowQuestionStatus::Answered !== $question->status) {
            return;
        }

        $session = $this->sessions->findById($question->session_id);

        if (null !== $session) {
            $this->guard->runInScope(
                LockScope::ofSession($session),
                function () use ($session, $question): void {
                    $session->refresh();

                    if (! $this->waitsFor($session, $question->node_id, $question->id)) {
                        $this->handOff->handOnIfStranded($session);

                        return;
                    }

                    $this->engine->runSession($session, resumedAfterDelay: true);
                },
            );
        }

        if (null !== $question->external_message_id) {
            $this->keyboardEditor->removeKeyboard($question->tenant_id, $question->contact_id, $question->session_id, $question->external_message_id);
        }
    }

    private function waitsFor(FlowSession $session, string $nodeId, string $questionId): bool
    {
        return FlowSessionStatus::Paused === $session->status
            && $nodeId === $session->current_node_id
            && data_get($session->state ?? [], AskPersonNodeHandler::markerKey($nodeId)) === $questionId;
    }
}
