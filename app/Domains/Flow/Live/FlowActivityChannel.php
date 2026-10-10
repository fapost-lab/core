<?php

declare(strict_types=1);

namespace App\Domains\Flow\Live;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Str;

/**
 * The private channel on which an assistant's flow activity is announced: a session started, moved on, waited or ended
 * (its logs are written in the same steps). The sessions and flow log screens listen to it and reload what they show.
 *
 * Who may listen: a user of the tenant whose host the request came to, who may view the assistant and its sessions. The
 * channel name carries the tenant as well as the assistant: a websocket server shared by several tenants (the SaaS) sees
 * one flat namespace of channels, and the name keeps them apart even before authorization.
 */
final readonly class FlowActivityChannel
{
    public const string PATTERN = 'tenant.{tenant}.assistant.{assistant}.flow';

    /** The name the event is broadcast as; Echo listens to it with a leading dot. */
    public const string EVENT = 'flow.activity';

    public function __construct(
        private TenantContextInterface $tenants,
        private Gate $gate,
        private FlowActivityWatchers $watchers,
    ) {
    }

    public static function name(string $tenantId, string $assistantId): string
    {
        return "tenant.{$tenantId}.assistant.{$assistantId}.flow";
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

        if (! $gate->allows('view', $model) || ! $gate->allows('viewAny', FlowSession::class)) {
            return false;
        }

        // Someone is listening from now on: announcements start.
        $this->watchers->watch($tenant, $assistant);

        return true;
    }
}
