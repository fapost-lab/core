<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\FlowQuestionContinuationSchedulerInterface;
use App\Domains\Flow\Contracts\FlowQuestionsInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\DTOs\FlowQuestionDraft;
use App\Domains\Flow\Enums\FlowQuestionPressOutcome;
use App\Domains\Flow\Enums\FlowQuestionStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Enums\SendMessageContentType;
use App\Domains\Flow\Models\FlowQuestion;
use App\Domains\Flow\Models\FlowSession;
use Carbon\CarbonImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * The questions of `ask_person` nodes (`flow_questions`).
 *
 * Every move out of `pending` is one conditional UPDATE, so of an answer, a timeout and a close only the first wins and
 * the others see `false` (or a late press). A press is decided only by the question's addressee, on the question's
 * assistant, with an index of one of its buttons; anything else changes nothing and tells nobody anything, so a forged
 * payload learns nothing either.
 */
final readonly class FlowQuestions implements FlowQuestionsInterface
{
    public function __construct(
        private MessageSenderInterface $sender,
        private FlowQuestionContinuationSchedulerInterface $continuations,
        private LoggerInterface $logger,
    ) {
    }

    public function open(FlowQuestionDraft $draft): FlowQuestion
    {
        $now = CarbonImmutable::now();

        // Insert-or-get by the step's execution key: a retried step finds the question (and the token) it asked first.
        // ON CONFLICT DO NOTHING, never a caught unique violation, which would abort a surrounding pgsql transaction.
        FlowQuestion::query()->insertOrIgnore([
            'id'               => (new FlowQuestion())->newUniqueId(),
            'tenant_id'        => $draft->tenantId,
            'token'            => bin2hex(random_bytes(16)),
            'session_id'       => $draft->sessionId,
            'node_id'          => $draft->nodeId,
            'execution_key'    => $draft->executionKey,
            'contact_id'       => $draft->contactId,
            'assistant_id'     => $draft->assistantId,
            'buttons'          => json_encode($draft->buttons, JSON_THROW_ON_ERROR),
            'status'           => FlowQuestionStatus::Pending->value,
            'expires_at'       => CarbonImmutable::instance($draft->expiresAt),
            'late_answer_text' => $draft->lateAnswerText,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);

        $question = FlowQuestion::query()
            ->where('session_id', $draft->sessionId)
            ->where('node_id', $draft->nodeId)
            ->where('execution_key', $draft->executionKey)
            ->first();

        if (! $question instanceof FlowQuestion) {
            throw new RuntimeException("The question of node '{$draft->nodeId}' in session '{$draft->sessionId}' could not be stored.");
        }

        return $question;
    }

    public function find(string $id): ?FlowQuestion
    {
        return FlowQuestion::query()->find($id);
    }

    public function attachMessage(string $id, string $externalMessageId): void
    {
        FlowQuestion::query()->whereKey($id)->update([
            'external_message_id' => $externalMessageId,
            'updated_at'          => CarbonImmutable::now(),
        ]);
    }

    public function expire(string $id): bool
    {
        $now = CarbonImmutable::now();

        return 1 === FlowQuestion::query()
            ->whereKey($id)
            ->where('status', FlowQuestionStatus::Pending->value)
            ->where('expires_at', '<=', $now)
            ->update(['status' => FlowQuestionStatus::Expired->value, 'updated_at' => $now]);
    }

    public function close(string $id): bool
    {
        return 1 === FlowQuestion::query()
            ->whereKey($id)
            ->where('status', FlowQuestionStatus::Pending->value)
            ->update(['status' => FlowQuestionStatus::Closed->value, 'updated_at' => CarbonImmutable::now()]);
    }

    public function receivePress(string $contactId, string $assistantId, string $token, int $index, string $updateId): FlowQuestionPressOutcome
    {
        $question = FlowQuestion::query()->where('token', $token)->first();

        if (! $question instanceof FlowQuestion
            || $question->contact_id !== $contactId
            || $question->assistant_id !== $assistantId
            || ! isset($question->buttons[$index]['id'])) {
            $this->logger->debug('flow.ask_person.press_ignored', [
                'contact_id'   => $contactId,
                'assistant_id' => $assistantId,
                'question_id'  => $question?->id,
            ]);

            return FlowQuestionPressOutcome::NotOurs;
        }

        $now = CarbonImmutable::now();

        if (FlowQuestionStatus::Pending !== $question->status || $question->expires_at->lte($now)) {
            return $this->late($question, $updateId);
        }

        if (! $this->runStillWaits($question)) {
            $this->close($question->id);

            return $this->late($question, $updateId);
        }

        $answered = 1 === FlowQuestion::query()
            ->whereKey($question->id)
            ->where('status', FlowQuestionStatus::Pending->value)
            ->where('expires_at', '>', $now)
            ->update([
                'status'           => FlowQuestionStatus::Answered->value,
                'answer_button_id' => (string) $question->buttons[$index]['id'],
                'answered_at'      => $now,
                'updated_at'       => $now,
            ]);

        if (! $answered) {
            return $this->late($question, $updateId);
        }

        $this->continuations->schedule($question->tenant_id, $question->id);

        return FlowQuestionPressOutcome::Accepted;
    }

    /**
     * Whether the run may still take this question's answer: it has not ended and is at the question's node. Not the
     * node's marker: the press may come before the step that asked has committed it. The continuation re-checks the
     * marker under the run's lock.
     */
    private function runStillWaits(FlowQuestion $question): bool
    {
        $session = FlowSession::query()->select(['id', 'status', 'current_node_id'])->find($question->session_id);

        return $session instanceof FlowSession
            && ! in_array($session->status, FlowSessionStatus::terminal(), true)
            && $session->current_node_id === $question->node_id;
    }

    /**
     * Tells the presser the decision was already made. Best effort: nothing waits on it.
     */
    private function late(FlowQuestion $question, string $updateId): FlowQuestionPressOutcome
    {
        $text = $question->late_answer_text;

        if (null !== $text && '' !== mb_trim($text)) {
            try {
                $this->sender->send(
                    tenantId: $question->tenant_id,
                    contactId: $question->contact_id,
                    sessionId: $question->session_id,
                    payload: [
                        'content_type'    => SendMessageContentType::Text->value,
                        'text'            => $text,
                        'session_id'      => $question->session_id,
                        'node_id'         => $question->node_id . ':late',
                        'idempotency_key' => "{$question->id}:late:{$updateId}",
                    ],
                );
            } catch (Throwable $exception) {
                $this->logger->warning('flow.ask_person.late_text_failed', [
                    'question_id' => $question->id,
                    'exception'   => $exception->getMessage(),
                ]);
            }
        }

        return FlowQuestionPressOutcome::Late;
    }
}
