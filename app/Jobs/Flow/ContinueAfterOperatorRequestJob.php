<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Conversation\Contracts\OperatorRequestsInterface;
use App\Domains\Conversation\DTO\OperatorRequest;
use App\Domains\Conversation\Enums\OperatorRequestState;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Contracts\InlineKeyboardEditorInterface;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Enums\SendMessageContentType;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Domains\Tenancy\Queue\StoppedTenantAction;
use App\Domains\Tenancy\Queue\TenantAccessGatedJob;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What the contact's side of a conversation does once an operator request changed state.
 *
 * Under the session lock of the contact at the assistant (it runs outside the routing pipeline):
 *
 * 1. takes the cancel button off the "request sent" message, once the request stops being pending;
 * 2. sends the node's text, rendered when the request was placed: "operator connected" on `accepted`, "operator left"
 *    on `returned` — keyed by the request and the state, so a retry never sends it twice;
 * 3. on a final state, moves the waiting session on through the node, which reads the outcome from the request and
 *    leaves by `returned`, `cancelled` or `timeout` — only while the session still waits in that node for that
 *    request. A session that ended meanwhile (the contact reset it, a subflow timed out) is not continued: the thread
 *    simply went back to the bot.
 *
 * The texts and the button work from the request alone, so they happen even when the session is gone.
 */
final class ContinueAfterOperatorRequestJob implements ShouldQueue, TenantAccessGatedJob
{
    use Dispatchable;
    use Queueable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $requestId,
        public readonly OperatorRequestState $state,
    ) {
        $this->onQueue('flow.execution');
    }

    public function accessModeTenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RespectsTenantAccessMode(StoppedTenantAction::Postpone)];
    }

    public function handle(TenantRepositoryInterface $tenants, TenantSwitcher $switcher, LoggerInterface $logger): void
    {
        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        $switcher->runForTenant($tenant, function () use ($logger): void {
            $request = app(OperatorRequestsInterface::class)->find($this->requestId);

            if (null === $request) {
                return;
            }

            app(FlowExecutionGuardInterface::class)->run(
                tenantId: $request->tenantId,
                contactId: $request->contactId,
                assistantId: $request->assistantId,
                callback: function () use ($request, $logger): void {
                    $this->removeCancelButton($request);
                    $this->tellContact($request, $logger);
                    $this->continueSession($request);
                },
            );
        });
    }

    private function removeCancelButton(OperatorRequest $request): void
    {
        if (OperatorRequestState::Returned === $this->state || null === $request->messageId || null === $request->messageSessionId) {
            return;
        }

        app(InlineKeyboardEditorInterface::class)->removeKeyboard(
            tenantId: $request->tenantId,
            contactId: $request->contactId,
            sessionId: $request->messageSessionId,
            externalMessageId: $request->messageId,
        );
    }

    private function tellContact(OperatorRequest $request, LoggerInterface $logger): void
    {
        $text = match ($this->state) {
            OperatorRequestState::Accepted => $request->connectedText,
            OperatorRequestState::Returned => $request->disconnectedText,
            default                        => null,
        };

        if (null === $text || '' === mb_trim($text)) {
            return;
        }

        try {
            app(MessageSenderInterface::class)->send(
                tenantId: $request->tenantId,
                contactId: $request->contactId,
                sessionId: $request->sessionId,
                payload: [
                    'content_type'    => SendMessageContentType::Text->value,
                    'text'            => $text,
                    'session_id'      => $request->sessionId,
                    'node_id'         => $request->nodeId,
                    'idempotency_key' => "{$request->id}:{$this->state->value}",
                ],
            );
        } catch (Throwable $exception) {
            // Best effort, like the button: the conversation goes on without the text, and a retry would only be
            // refused as a duplicate if it had gone out.
            $logger->warning('flow.request_operator.contact_text_failed', [
                'request_id' => $request->id,
                'state'      => $this->state->value,
                'exception'  => $exception->getMessage(),
            ]);
        }
    }

    private function continueSession(OperatorRequest $request): void
    {
        if ($this->state->isWaiting()) {
            return;
        }

        $session = FlowSession::query()->find($request->sessionId);

        if (! $session instanceof FlowSession) {
            return;
        }

        $session->refresh();

        $waitsHere = FlowSessionStatus::WaitingInput === $session->status
            && $session->current_node_id === $request->nodeId
            && data_get($session->state ?? [], SystemStateKeys::REQUEST_OPERATOR_PREFIX . ".{$request->nodeId}") === $request->id;

        if (! $waitsHere) {
            return;
        }

        app(FlowEngineInterface::class)->resume(
            $session,
            new IncomingMessage(
                updateId: "operator_request:{$request->id}:{$this->state->value}",
                externalUserId: '',
                externalChatId: '',
                text: null,
                type: IncomingMessageType::Unknown,
                platform: $request->platform,
                payload: [
                    'operator_request' => $request->id,
                    'state'            => $this->state->value,
                ],
            ),
        );
    }
}
