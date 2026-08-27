<?php

declare(strict_types=1);

namespace App\Domains\Flow\Routing;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;
use App\Domains\Flow\Contracts\FallbackMessageServiceInterface;
use App\Domains\Tenancy\Settings\TenantSettings;

/**
 * Applies the assistant's {@code busy_message} when an inbound message has
 * to be dropped because the session is locked or paused. Silent-drop
 * decisions just no-op here (kept centralised so future analytics hooks
 * have a single place to plug in).
 *
 * Per ADR Message Routing § Drop Policy:
 *   - Lock acquisition timeout / paused session → busy notice
 *   - Active state (race) / paused_subflow      → silent drop
 *
 * Resolution order for the busy notice:
 *   1. assistant.busy_message  (tenant-configured override)
 *   2. translator['errors.busy', contact.language]  (catalog default,
 *      respecting tenant_translations overrides on this key)
 */
final readonly class DropPolicy implements DropPolicyInterface
{
    public const string BUSY_TRANSLATION_KEY = 'errors.busy';

    public function __construct(
        private FallbackMessageServiceInterface $messenger,
        private ContentTranslatorInterface $translator,
        private TenantSettings $tenantSettings,
    ) {
    }

    public function applyBusy(Contact $contact, Assistant $assistant): void
    {
        $language = $this->resolveLanguage($contact);
        $custom   = $this->resolveLocalized($assistant->busy_message, $language);

        $message = null !== $custom && '' !== $custom
            ? $custom
            : $this->translator->translate(self::BUSY_TRANSLATION_KEY, $language);

        $this->messenger->send($contact, (string)$assistant->getKey(), $message);
    }

    public function applySilent(Contact $contact, Assistant $assistant): void
    {
        // Intentionally a no-op. Reserved for analytics emission once dashboards land.
    }

    /**
     * Tenant-authored messages are stored as `{ lang: text }` locale maps
     * (jsonb). Old rows written before the localization migration may still
     * arrive as plain strings — the translator's resolveField handles both
     * shapes uniformly. Returns null when no usable text exists.
     */
    private function resolveLocalized(mixed $field, string $language): ?string
    {
        if ( ! is_array($field) && ! is_string($field)) {
            return null;
        }

        $resolved = $this->translator->resolveField($field, $language);

        return '' === mb_trim($resolved) ? null : $resolved;
    }

    /**
     * The drop policy runs before any flow session is established, so the
     * usual session-state language is unavailable — we resolve directly off
     * the contact's stored language with a tenant fallback.
     */
    private function resolveLanguage(Contact $contact): string
    {
        if (is_string($contact->language) && '' !== $contact->language) {
            return $contact->language;
        }

        return $this->tenantSettings->fallback_language;
    }
}
