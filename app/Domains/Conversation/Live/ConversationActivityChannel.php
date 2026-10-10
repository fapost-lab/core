<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Live;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Str;

/**
 * The private channel on which an assistant's conversation activity is announced. The inbox list and a thread's page
 * listen to it and reload what they show.
 *
 * Who may listen: a user of the tenant whose host the request came to, who may view the assistant and its
 * conversations. The name carries the tenant as well as the assistant, as {@see \App\Domains\Flow\Live\FlowActivityChannel}
 * does, so a websocket server shared by several tenants keeps them apart even before authorization.
 */
final readonly class ConversationActivityChannel
{
    public const string PATTERN = 'tenant.{tenant}.assistant.{assistant}.conversations';

    /** The name the event is broadcast as; Echo listens to it with a leading dot. */
    public const string EVENT = 'conversation.activity';

    public function __construct(
        private TenantContextInterface $tenants,
        private Gate $gate,
        private ConversationActivityWatchers $watchers,
    ) {
    }

    public static function name(string $tenantId, string $assistantId): string
    {
        return "tenant.{$tenantId}.assistant.{$assistantId}.conversations";
    }

    /**
     * Laravel passes the channel's parameters in the order of {@see self::PATTERN}.
     */
    public function join(User $user, string $tenant, string $assistant): bool
    {
        if (! $this->tenants->isResolved() || $this->tenants->get()->getId() !== $tenant || ! Str::isUuid($assistant)) {
            return false;
        }

        $model = Assistant::query()->find($assistant);

        if (! $model instanceof Assistant || (string) $model->tenant_id !== $tenant) {
            return false;
        }

        $gate = $this->gate->forUser($user);

        if (! $gate->allows('view', $model) || ! $gate->allows('viewAny', Conversation::class)) {
            return false;
        }

        $this->watchers->watch($tenant, $assistant);

        return true;
    }
}
