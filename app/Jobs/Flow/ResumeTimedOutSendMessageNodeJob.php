<?php

declare(strict_types=1);

namespace App\Jobs\Flow;

use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\IncomingMessageType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

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
    }

    public function handle(
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
        FlowEngineInterface $engine,
    ): void {
        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        $switcher->runForTenant($tenant, function () use ($engine): void {
            $session = FlowSession::query()->find($this->sessionId);

            if (! $session instanceof FlowSession || $session->current_node_id !== $this->nodeId) {
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
        });
    }
}
