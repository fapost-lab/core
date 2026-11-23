<?php

declare(strict_types=1);

namespace App\Domains\Flow\Webhook;

use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Models\FlowSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers a waiting webhook sender or API caller when the run its request started has ended and no `webhook_response` node answered
 * first: 200 `{"status":"completed"}` (or `cancelled`), 500 `{"status":"failed"}` ({@see WebhookReply::forRunEnd()}).
 *
 * {@see \App\Domains\Flow\Services\FlowEngine} calls it whenever it stops running a session. Only the root of an
 * automation run a webhook or an API call started counts — told apart in memory before any query, so a conversation pays nothing; a
 * subflow child never answers, its parent's own end does. The status is read fresh from the row, so only an end that
 * was committed answers. A node's earlier answer wins ({@see WebhookReplyStoreInterface}), and calling this again for
 * the same run changes nothing.
 *
 * Never fails the caller: the run's steps are done, and a failed job would be retried and repeat their side effects.
 */
final readonly class WebhookRunEndReply
{
    public function __construct(
        private FlowSessionRepositoryInterface $sessions,
        private WebhookReplyStoreInterface $replies,
        private LoggerInterface $logger,
    ) {
    }

    public function afterRun(FlowSession $session): void
    {
        $triggerId = $session->trigger_id;
        $fireKey   = $session->fire_key;

        if (FlowRunKind::Automation !== $session->run_kind
            || (is_string($session->parent_session_id) && '' !== $session->parent_session_id)
            || ! is_string($triggerId)
            || ! is_string($fireKey)
            || ! InboundFireKey::isInbound($fireKey)) {
            return;
        }

        try {
            $root = $this->sessions->findById((string) $session->getKey());
            $end  = null === $root ? null : WebhookRunEnd::of($root->status, is_string($root->end_status) ? $root->end_status : null);

            if (null === $end) {
                return;
            }

            $this->replies->keep((string) $session->tenant_id, $triggerId, $fireKey, WebhookReply::forRunEnd($end));
        } catch (Throwable $exception) {
            $this->logger->warning('flow.webhook.run_end_reply_failed', [
                'session_id' => (string) $session->getKey(),
                'error'      => $exception->getMessage(),
            ]);
        }
    }
}
