<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Providers;

use App\Domains\Conversation\Contracts\ConversationLoggerInterface;
use App\Domains\Conversation\Contracts\ConversationOwnershipInterface;
use App\Domains\Conversation\Contracts\ConversationReplyServiceInterface;
use App\Domains\Conversation\Contracts\ConversationStoreInterface;
use App\Domains\Conversation\Models\Conversation;
use App\Domains\Conversation\Policies\ConversationPolicy;
use App\Domains\Conversation\Services\ConversationLogger;
use App\Domains\Conversation\Services\ConversationOwnership;
use App\Domains\Conversation\Services\ConversationReplyService;
use App\Domains\Conversation\Store\Postgres\PostgresConversationStore;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Wires the Conversation domain: the write-port (logger) and the storage driver
 * selected by config. Everything downstream depends only on the interfaces, so a
 * new driver is a one-line binding change here.
 */
final class ConversationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(
            ConversationLoggerInterface::class,
            static fn (Application $app): ConversationLogger => new ConversationLogger(
                enabled: (bool) config('conversation.enabled', true),
                logger: $app->make(LoggerInterface::class),
                tenantContext: $app->make(TenantContextInterface::class),
            ),
        );

        $this->app->scoped(ConversationStoreInterface::class, static function (Application $app): ConversationStoreInterface {
            $driver = (string) config('conversation.driver', 'postgres');

            return match ($driver) {
                'postgres' => $app->make(PostgresConversationStore::class),
                default    => throw new InvalidArgumentException("Unsupported conversation store driver: {$driver}"),
            };
        });

        $this->app->scoped(ConversationOwnershipInterface::class, ConversationOwnership::class);
        $this->app->scoped(ConversationReplyServiceInterface::class, ConversationReplyService::class);
    }

    public function boot(): void
    {
        Gate::policy(Conversation::class, ConversationPolicy::class);
    }
}
