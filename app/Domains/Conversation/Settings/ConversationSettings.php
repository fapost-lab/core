<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Settings;

use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Settings\TenantSettings;
use Illuminate\Database\QueryException;
use Spatie\LaravelSettings\Exceptions\MissingSettings;

/**
 * Whether the tenant in whose context the code runs keeps a transcript, and downloads inbound media into its storage.
 *
 * Both are the tenant's own settings (`conversation_logging`, `conversation_media_fetch`); an unset one (null) means
 * "the install default", which is the value of `CONVERSATION_LOGGING` / `CONVERSATION_MEDIA_FETCH` carried in by the
 * service provider. The same default answers outside a tenant and when the settings cannot be read.
 *
 * Read from a fresh `new TenantSettings()` and memoized per tenant id for the life of the scope (a request or a
 * job); bound `scoped`, never as a singleton, so a long-lived worker carries nothing from one job into the next.
 * The fresh build is deliberate: `TenantSwitcher::runForTenant()` hides the scoped instance the container built for
 * the length of a switch, so the container's own TenantSettings may belong to another tenant or to none. A fresh
 * instance loads lazily from the connection that is current, which is the tenant's own. See
 * {@see \App\Domains\Messaging\TenantMessagingRateLimit} for the same reasoning.
 */
final class ConversationSettings
{
    /** @var array<string, array{logging: bool, media: bool}> */
    private array $perTenant = [];

    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly bool $defaultLogging,
        private readonly bool $defaultMediaFetch,
    ) {
    }

    public function loggingEnabled(): bool
    {
        return $this->current()['logging'];
    }

    public function mediaFetchEnabled(): bool
    {
        return $this->current()['media'];
    }

    /**
     * @return array{logging: bool, media: bool}
     */
    private function current(): array
    {
        if (! $this->tenantContext->isResolved()) {
            return $this->defaults();
        }

        $tenantId = (string) $this->tenantContext->get()->getId();

        return $this->perTenant[$tenantId] ??= $this->read();
    }

    /**
     * The current tenant's stored values, the install default for each one that is unset.
     *
     * An unreadable settings table is reported but never stops a message.
     *
     * @return array{logging: bool, media: bool}
     */
    private function read(): array
    {
        try {
            $settings = new TenantSettings();

            return [
                'logging' => $settings->conversation_logging ?? $this->defaultLogging,
                'media'   => $settings->conversation_media_fetch ?? $this->defaultMediaFetch,
            ];
        } catch (MissingSettings) {
            // Defensive: unreachable while every TenantSettings property has a class default.
            return $this->defaults();
        } catch (QueryException $exception) {
            report($exception);

            return $this->defaults();
        }
    }

    /**
     * @return array{logging: bool, media: bool}
     */
    private function defaults(): array
    {
        return ['logging' => $this->defaultLogging, 'media' => $this->defaultMediaFetch];
    }
}
