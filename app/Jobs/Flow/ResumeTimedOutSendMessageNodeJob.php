<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\DTO\IncomingMessageType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Resumes a session whose `send_message` node timed out waiting for a reply.
 *
 * Runs outside the routing pipeline, so it claims the session lock itself and
 * re-reads the session under it: the contact may have answered while this job
 * was queued, and the session is then no longer on this node — a silent no-op.
 * A busy lock surfaces as {@see \App\Domains\Flow\Exceptions\SessionLockTimeoutException}
 * and the queue retries the job.
 */
final class ResumeTimedOutSendMessageNodeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $sessionId,
        public readonly string $nodeId,
        public readonly string $platform,
    ) {
        $this->onQueue('flow.execution');
    }

    public function handle(
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
        FlowEngineInterface $engine,
        FlowExecutionGuardInterface $guard,
    ): void {
        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        $switcher->runForTenant($tenant, function () use ($engine, $guard): void {
            $session = FlowSession::query()->find($this->sessionId);

            if (! $session instanceof FlowSession) {
                return;
            }

            $guard->run(
                tenantId: (string)$session->tenant_id,
                contactId: (string)$session->contact_id,
                assistantId: (string)$session->assistant_id,
                callback: function () use ($engine, $session): void {
                    $session->refresh();

                    if ($session->current_node_id !== $this->nodeId) {
                        return;
                    }

                    $engine->resume(
                        $session,
                        new IncomingMessage(
                            updateId: "timeout:{$this->sessionId}:{$this->nodeId}",
                            externalUserId: '',
                            externalChatId: '',
                            text: null,
                            type: IncomingMessageType::Unknown,
                            platform: $this->platform,
                            payload: [
                                'send_message_timeout' => true,
                                'node_id'              => $this->nodeId,
                            ],
                        )
                    );
                },
            );
        });
    }
}
