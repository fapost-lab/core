<?php

declare(strict_types=1);

namespace App\Domains\Channels\Services;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Channels\DTOs\ChannelLimitStatus;
use App\Domains\Channels\Models\Channel;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Quota\Contracts\RecordQuotaInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Reads and writes the {@see Channel}s of one assistant, which the caller names.
 *
 * Every read is bounded by the tenant and the assistant, explicitly, because the console does not register Filament's
 * tenancy scope: a channel of another assistant or tenant is "not found", never "forbidden". The assistant is an
 * argument, not a dependency, so a screen that gets it from its own URL (the tenant-wide admin) can use the service
 * as the assistant console does. The writes are {@see ChannelServiceInterface}'s: it checks the channel limit, keeps
 * the webhook routing in step and is the only path that rotates the webhook hash.
 */
final readonly class AssistantChannelService
{
    public function __construct(
        private TenantContextInterface $tenants,
        private ChannelServiceInterface $channels,
        private RecordQuotaInterface $recordQuota,
    ) {
    }

    /**
     * The channels of the assistant, for a list.
     *
     * @return Builder<Channel>
     */
    public function query(Assistant $assistant): Builder
    {
        return Channel::query()
            ->where('channels.tenant_id', $this->tenants->get()->getId())
            ->where('channels.assistant_id', (string) $assistant->getKey());
    }

    /**
     * @throws ModelNotFoundException when the id is not a channel of the assistant
     */
    public function findFor(Assistant $assistant, string $id): Channel
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(Channel::class, [$id]);
        }

        // The policy looks at the channel's assistant.
        return $this->query($assistant)->with('assistant')->whereKey($id)->firstOrFail();
    }

    /**
     * An unsaved channel of the assistant, to ask the policy about abilities that need a record (`update`, `delete`,
     * `rotateWebhook`) when only the screen's own answer is wanted. The policy looks at the record's assistant, so it is set.
     */
    public function abilityProbe(Assistant $assistant): Channel
    {
        return Channel::query()->getModel()->newInstance()->setRelation('assistant', $assistant);
    }

    /**
     * Where the tenant stands against its channel limit (the count is tenant-wide, inactive channels included).
     */
    public function limit(): ChannelLimitStatus
    {
        $current = Channel::countForLimit();

        return new ChannelLimitStatus(
            current: $current,
            limit: $this->recordQuota->limit(Channel::LIMIT_KEY),
            reached: ! $this->recordQuota->canCreate(Channel::LIMIT_KEY, $current),
        );
    }

    /**
     * @param  array<string, mixed>  $fields  `type`, `token`, `secret_token`, `config`, `is_active`
     *
     * @throws RecordLimitReachedException when the tenant is at its channel limit
     */
    public function create(Assistant $assistant, array $fields): Channel
    {
        return $this->channels->create($assistant, $fields);
    }

    /**
     * Changes what the form edits. The type never changes here, and a token that is not in `$fields` stays as it is.
     *
     * @param  array<string, mixed>  $fields  `config`, `is_active` and, when they are to change, `token` and `secret_token`
     */
    public function update(Channel $channel, array $fields): Channel
    {
        return $this->channels->update($channel, $fields);
    }

    public function rotateWebhookHash(Channel $channel): Channel
    {
        return $this->channels->rotateWebhookHash($channel);
    }

    public function reregisterWebhook(Channel $channel): Channel
    {
        return $this->channels->reregisterWebhook($channel);
    }

    /**
     * Deletes the channel itself, not through a query: the observer takes the webhook routing out and deregisters the
     * webhook at the provider, and a single `DELETE` would skip it.
     */
    public function delete(Channel $channel): void
    {
        $channel->delete();
    }
}
