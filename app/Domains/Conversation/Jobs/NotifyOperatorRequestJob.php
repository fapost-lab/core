<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Jobs;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Conversation\Contracts\OperatorRequestsInterface;
use App\Domains\Conversation\Enums\OperatorRequestState;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Notifications\ConversationOperatorNotifier;
use App\Domains\Conversation\Services\ConversationOperators;
use App\Domains\Conversation\Support\ContactLabel;
use App\Domains\Staff\Enums\StaffNotifyChannel;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Notifications\StaffMessage;
use App\Domains\Staff\Notifications\StaffNotifierRegistry;
use App\Domains\Staff\Notifications\StaffRecipientResolver;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Queue\RespectsTenantAccessMode;
use App\Domains\Tenancy\Queue\StoppedTenantAction;
use App\Domains\Tenancy\Queue\TenantAccessGatedJob;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tells the operators a contact waits for one: in their bell always, by email when the node asks for it.
 *
 * The recipients are the node's staff selection (`notify`'s modes) narrowed to those who may take the assistant's
 * threads (both conversation permissions and access to the assistant); when nobody is left, the administrators who
 * may. Runs on `messaging.system` inside the tenant's switch, once per request: a retry or a node that placed the same
 * request again delivers nothing more. A request that stopped waiting before the job ran notifies nobody.
 */
final class NotifyOperatorRequestJob implements ShouldQueue, TenantAccessGatedJob
{
    use Queueable;

    /** How long a delivered request is remembered against a second delivery. */
    private const int GUARD_SECONDS = 86400;

    /**
     * @param  array{target: string, role: string|null, user_ids: list<string>}  $recipients
     */
    public function __construct(
        public string $tenantId,
        public string $requestId,
        public array $recipients,
        public bool $byEmail,
        public ?string $operatorNote,
    ) {
        $this->onQueue('messaging.system');
        $this->afterCommit();
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
        return [new RespectsTenantAccessMode(StoppedTenantAction::Drop)];
    }

    public function handle(
        TenantRepositoryInterface $tenants,
        TenantSwitcher $switcher,
        Cache $cache,
        LoggerInterface $logger,
    ): void {
        if (! $cache->add("operator-request-notify:{$this->tenantId}:{$this->requestId}", true, self::GUARD_SECONDS)) {
            return;
        }

        $tenant = $tenants->findById($this->tenantId);

        if (null === $tenant) {
            return;
        }

        $switcher->runForTenant($tenant, function () use ($logger): void {
            $requests = app(OperatorRequestsInterface::class);
            $request  = $requests->find($this->requestId);

            if (null === $request || OperatorRequestState::Pending !== $request->state) {
                return;
            }

            $conversation = Conversation::query()->with('contact')->find($request->conversationId);
            $assistant    = Assistant::query()->find($request->assistantId);

            if (! $conversation instanceof Conversation || ! $assistant instanceof Assistant) {
                return;
            }

            $recipients = $this->recipients($assistant);

            if ($recipients->isEmpty()) {
                $logger->info('conversation.operator_request.no_recipients', [
                    'request_id'   => $this->requestId,
                    'assistant_id' => $request->assistantId,
                ]);

                return;
            }

            $notifier = app(ConversationOperatorNotifier::class);
            $email    = $this->byEmail ? app(StaffNotifierRegistry::class)->get(StaffNotifyChannel::Email->value) : null;
            $path     = app(UrlGenerator::class)->route('console.conversations.show', [
                'assistant' => $request->assistantId,
                'record'    => $request->conversationId,
            ], false);
            $contact = ContactLabel::of($conversation);

            foreach ($recipients as $recipient) {
                $notifier->requested($recipient, $conversation, $this->requestId, $this->operatorNote);

                if (null === $email || ! $email->isAvailableFor($recipient)) {
                    continue;
                }

                try {
                    $email->send($recipient, $this->emailMessage($recipient, $contact, $path));
                } catch (Throwable $exception) {
                    // One failed mail must not fail the job: a retry would deliver nothing (the guard is spent).
                    $logger->warning('conversation.operator_request.email_failed', [
                        'request_id' => $this->requestId,
                        'user_id'    => $recipient->getKey(),
                        'exception'  => $exception->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(Assistant $assistant): Collection
    {
        $resolver  = app(StaffRecipientResolver::class);
        $operators = app(ConversationOperators::class);

        $chosen = $operators->whoCanTake($assistant, $resolver->resolve($this->recipients, (string) $assistant->getKey()));

        return $chosen->isNotEmpty() ? $chosen : $operators->whoCanTake($assistant, $resolver->admins());
    }

    private function emailMessage(User $recipient, string $contact, string $path): StaffMessage
    {
        $locale = $recipient->preferredLocale();
        $title  = (string) trans('conversation.operator_notifications.requested.title', ['contact' => $contact], $locale);
        $body   = $this->operatorNote ?? (string) trans('conversation.operator_notifications.requested.body', ['contact' => $contact], $locale);

        return new StaffMessage(
            text: $title . "\n\n" . $body,
            url: $path,
            actionLabel: (string) trans('conversation.operator_notifications.open', [], $locale),
        );
    }
}
