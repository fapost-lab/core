<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Jobs;

use App\Domains\Assistant\Contracts\CurrentAssistantInterface;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Domains\Webhook\DTOs\IncomingMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class IncomingMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $backoff = 5;

    public function __construct(
        public readonly IncomingMessage $message,
        public readonly string $tenantId,
        public readonly string $assistantId,
        public readonly string $channelId,
        public readonly string $schema,
    ) {
    }

    public function handle(
        TenantSwitcher $switcher,
        CurrentAssistantInterface $currentAssistant,
        ContactServiceInterface $contactService,
    ): void {
        $tenant = new RuntimeTenant(
            id: $this->tenantId,
            schemaName: $this->schema,
        );

        $switcher->runForTenant($tenant, function () use ($contactService, $currentAssistant): void {
            $assistant = Assistant::query()->findOrFail($this->assistantId);
            $currentAssistant->set($assistant);

            $contact = $contactService->findOrCreate(
                tenantId: $this->tenantId,
                platform: $this->message->platform,
                externalId: $this->message->externalUserId,
                meta: $this->message->meta,
            );

            $contactService->findOrCreateChannelContact(
                contact: $contact,
                channelId: $this->channelId,
            );
        });
    }
}
