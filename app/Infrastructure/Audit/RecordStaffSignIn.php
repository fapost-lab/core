<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Staff\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Records a staff user's sign-in and explicit sign-out. The platform support user is skipped: its entries and exits are
 * the support access log's (`support_access_entries`), which the audit log links to and does not repeat.
 *
 * Fails open: a log that cannot be written must not lock everyone out, so the error is reported and the sign-in goes
 * on. The insert runs in a transaction of its own (a savepoint inside an outer one), so on PostgreSQL a failed insert
 * does not abort the surrounding transaction.
 */
final readonly class RecordStaffSignIn
{
    public function __construct(
        private AuditTrailInterface $trail,
        private ConnectionInterface $connection,
        private ExceptionHandler $exceptions,
    ) {
    }

    public function handle(Login|Logout $event): void
    {
        if (AuditActorResolver::STAFF_GUARD !== $event->guard) {
            return;
        }

        $user = $event->user;

        if (! $user instanceof User || $user->isPlatformSupport()) {
            return;
        }

        $record = new AuditRecord(
            action: $event instanceof Login ? AuditAction::SignedIn : AuditAction::SignedOut,
            subject: $user,
            subjectLabel: $user->name,
            actor: AuditActorResolver::staff($user),
        );

        try {
            $this->connection->transaction(fn () => $this->trail->record($record));
        } catch (Throwable $throwable) {
            $this->exceptions->report($throwable);
        }
    }
}
