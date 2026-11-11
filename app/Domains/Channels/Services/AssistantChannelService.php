<?php

declare(strict_types=1);

namespace App\Domains\Channels\Services;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditChanges;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Channels\DTOs\ChannelLimitStatus;
use App\Domains\Channels\Models\Channel;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Quota\Contracts\RecordQuotaInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reads and writes the {@see Channel}s of one assistant, which the caller names.
 *
 * Every read is bounded by the tenant and the assistant, explicitly, because no global scope does it
 * implicitly: a channel of another assistant or tenant is "not found", never "forbidden". The assistant is an
 * argument, not a dependency, so a screen that gets it from its own URL (the tenant-wide admin) can use the service
 * as the assistant console does. The writes are {@see ChannelServiceInterface}'s: it checks the channel limit, keeps
 * the webhook routing in step and is the only path that rotates the webhook hash.
 *
 * Each write and its audit entry commit together, in one transaction; the routing and the provider are reached after
 * the commit, as the observer always defers them. Only the type and the active flag are recorded with their
 * values; a changed config is a marker, and the credentials are never named: a new token or secret token is recorded as
 * its own action, `channel.token_rotated`, with no value.
 */
final readonly class AssistantChannelService
{
    public function __construct(
        private TenantContextInterface $tenants,
        private ChannelServiceInterface $channels,
        private RecordQuotaInterface $recordQuota,
        private AuditTrailInterface $audit,
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
        $channel = DB::transaction(function () use ($assistant, $fields): Channel {
            $channel = $this->channels->create($assistant, $fields);

            $this->record(AuditAction::ChannelCreated, $channel, AuditChanges::created([
                'type'      => $channel->type,
                'is_active' => (bool) $channel->is_active,
            ]));

            return $channel;
        });

        return self::afterCommit($channel);
    }

    /**
     * Changes what the form edits. The type never changes here, and a token that is not in `$fields` stays as it is.
     *
     * @param  array<string, mixed>  $fields  `config`, `is_active` and, when they are to change, `token` and `secret_token`
     */
    public function update(Channel $channel, array $fields): Channel
    {
        $updated = DB::transaction(function () use ($channel, $fields): Channel {
            $before = self::snapshot($channel);

            $updated = $this->channels->update($channel, $fields);
            $after   = self::snapshot($updated);

            $changes = AuditChanges::between($before, $after, ['type', 'is_active'], ['config']);

            if (! $changes->isEmpty()) {
                $this->record(AuditAction::ChannelUpdated, $updated, $changes);
            }

            // Compared in memory only: neither credential is ever written to the log.
            if ($before['token'] !== $after['token'] || $before['secret_token'] !== $after['secret_token']) {
                $this->record(AuditAction::ChannelTokenRotated, $updated);
            }

            return $updated;
        });

        return self::afterCommit($updated);
    }

    public function rotateWebhookHash(Channel $channel): Channel
    {
        $rotated = DB::transaction(function () use ($channel): Channel {
            $rotated = $this->channels->rotateWebhookHash($channel);

            $this->record(AuditAction::ChannelWebhookHashRotated, $rotated);

            return $rotated;
        });

        return self::afterCommit($rotated);
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
        DB::transaction(function () use ($channel): void {
            $channel->delete();

            $this->record(AuditAction::ChannelDeleted, $channel);
        });
    }

    /**
     * The channel as stored once the transaction committed: the routing and the provider are reached after the commit
     * (the observer defers them), and the provider's answer is the channel's stored webhook status.
     */
    private static function afterCommit(Channel $channel): Channel
    {
        return $channel->fresh() ?? $channel;
    }

    /**
     * What a write may change, read before and after it. The credentials are here only to tell whether they changed.
     *
     * @return array{type: string, is_active: bool, config: mixed, token: string, secret_token: string}
     */
    private static function snapshot(Channel $channel): array
    {
        return [
            'type'         => (string) $channel->type,
            'is_active'    => (bool) $channel->is_active,
            'config'       => $channel->config,
            'token'        => (string) $channel->token,
            'secret_token' => (string) $channel->secret_token,
        ];
    }

    private function record(AuditAction $action, Channel $channel, ?AuditChanges $changes = null): void
    {
        $this->audit->record(new AuditRecord(
            action: $action,
            subject: $channel,
            subjectLabel: $channel->type,
            assistantId: (string) $channel->assistant_id,
            changes: $changes,
        ));
    }
}
