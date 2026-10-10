<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Services;

use App\Domains\Assistant\Contracts\AssistantServiceInterface;
use App\Domains\Assistant\DTOs\AssistantLimitStatus;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Quota\Contracts\RecordQuotaInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * The assistants a staff member manages from the admin panel: the ones of the current tenant they may see (an
 * administrator all of them, anyone else the ones assigned to them), and creating, changing and deleting them.
 *
 * Every read is bounded by the tenant and, for a non-administrator, the assignment, explicitly, because the console
 * has no Filament scope: an assistant the user may not see is "not found", never "forbidden". The writes are
 * {@see AssistantServiceInterface}'s, which checks the assistant limit.
 */
final readonly class StaffAssistantService
{
    public function __construct(
        private TenantContextInterface $tenants,
        private AssistantServiceInterface $assistants,
        private RecordQuotaInterface $recordQuota,
        private ConnectionInterface $connection,
    ) {
    }

    /**
     * The assistants the user may see, for a list.
     *
     * @return Builder<Assistant>
     */
    public function query(User $user): Builder
    {
        $query = Assistant::query()->where('assistants.tenant_id', $this->tenants->get()->getId());

        if (! $user->isAdmin()) {
            $query->whereHas('users', static fn (Builder $users): Builder => $users->whereKey($user->getKey()));
        }

        return $query;
    }

    /**
     * @throws ModelNotFoundException when the id is not an assistant the user may see
     */
    public function findFor(User $user, string $id): Assistant
    {
        if (! Str::isUuid($id)) {
            throw (new ModelNotFoundException())->setModel(Assistant::class, [$id]);
        }

        return $this->query($user)->whereKey($id)->firstOrFail();
    }

    /**
     * Where the tenant stands against its assistant limit. The count is the one {@see AssistantService::create}
     * checks, so the screen and the write agree on whether there is room.
     */
    public function limit(): AssistantLimitStatus
    {
        $current = Assistant::query()->count();

        return new AssistantLimitStatus(
            current: $current,
            limit: $this->recordQuota->limit(Assistant::LIMIT_KEY),
            reached: ! $this->recordQuota->canCreate(Assistant::LIMIT_KEY, $current),
        );
    }

    /**
     * Creates the assistant in the current tenant. A creator who is not an administrator is assigned to it, so they
     * keep seeing what they made; an administrator sees every assistant and is not assigned.
     *
     * @param  array{name: string, default_language: string, is_active: bool}  $fields
     *
     * @throws RecordLimitReachedException when the tenant is at its assistant limit
     */
    public function create(User $creator, array $fields): Assistant
    {
        return $this->connection->transaction(function () use ($creator, $fields): Assistant {
            $assistant = $this->assistants->create($this->tenants->get(), $fields);

            if (! $creator->isAdmin()) {
                $creator->assistants()->syncWithoutDetaching([(string) $assistant->getKey()]);
            }

            return $assistant;
        });
    }

    /**
     * Changes what the admin form edits. `is_active` is written as is, without the channel cascade, as the Filament
     * form wrote it (see {@see AssistantServiceInterface::update}).
     *
     * @param  array{name: string, default_language: string, is_active: bool}  $fields
     */
    public function update(Assistant $assistant, array $fields): Assistant
    {
        return $this->assistants->update($assistant, $fields);
    }

    /**
     * Deletes the assistant itself, not through a query: the model deletes its channels one by one first, and the
     * channel observer takes each webhook out of the routing and off the provider.
     */
    public function delete(Assistant $assistant): void
    {
        $assistant->delete();
    }
}
