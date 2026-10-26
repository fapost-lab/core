<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Conversation\Settings\ConversationSettings;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Settings\TenantSettings;

/**
 * The tenant's settings screen in the admin panel: the language matrix (content base, available, fallback), the
 * messaging limit, the flow runtime defaults and the broadcast pacing and the conversation log — every field of {@see TenantSettings} except
 * the webhook ones, which no screen edits and which a save leaves as stored.
 *
 * It lives in Flow, not Tenancy, because the one rule that is not a plain value check is a flow-content rule: the
 * content base language is locked once the tenant has any flow definition, since flow JSON keys its texts by language
 * and a new base would relabel what is written instead of translating it (ADR-15).
 */
final readonly class TenantSettingsEditor
{
    public function __construct(
        private TenantSettings $settings,
        private TenantContextInterface $tenant,
        private ConversationSettings $conversation,
    ) {
    }

    /**
     * The languages without blanks and repeats, in the order chosen.
     *
     * @param  array<array-key, mixed>  $languages
     *
     * @return list<string>
     */
    public static function languageList(array $languages): array
    {
        return array_values(array_unique(array_filter(
            $languages,
            static fn (mixed $code): bool => is_string($code) && '' !== $code,
        )));
    }

    /**
     * The available languages with the content base language among them: the base is what every flow is written in,
     * so it is always available. Kept first when it had to be added; an order already chosen is left alone.
     *
     * @param  array<array-key, mixed>  $available
     *
     * @return list<string>
     */
    public static function withBase(string $base, array $available): array
    {
        $languages = self::languageList($available);

        return in_array($base, $languages, true) ? $languages : [$base, ...$languages];
    }

    /**
     * The values the form starts from. The conversation fields are the effective ones: a tenant that has stored
     * none sees the install default, which a save then stores explicitly.
     *
     * @return array{content_base_language: string, available_languages: list<string>, fallback_language: string, messaging_rate_limit: int, broadcast_chunk_size: int, broadcast_backpressure: bool, conversation_logging: bool, conversation_media_fetch: bool, flow_session_ttl: int, max_retry_attempts: int, flow_fallback_message: string}
     */
    public function state(): array
    {
        return [
            'content_base_language'    => $this->settings->content_base_language,
            'available_languages'      => array_values($this->settings->available_languages),
            'fallback_language'        => $this->settings->fallback_language,
            'messaging_rate_limit'     => $this->settings->messaging_rate_limit,
            'broadcast_chunk_size'     => $this->settings->broadcast_chunk_size,
            'broadcast_backpressure'   => $this->settings->broadcast_backpressure,
            'conversation_logging'     => $this->conversation->loggingEnabled(),
            'conversation_media_fetch' => $this->conversation->mediaFetchEnabled(),
            'flow_session_ttl'         => $this->settings->flow_session_ttl,
            'max_retry_attempts'       => $this->settings->max_retry_attempts,
            'flow_fallback_message'    => $this->settings->flow_fallback_message,
        ];
    }

    public function contentBaseLanguage(): string
    {
        return $this->settings->content_base_language;
    }

    /**
     * Whether the content base language may no longer change: the tenant has a flow definition (a published flow; drafts do not count).
     */
    public function baseLanguageLocked(): bool
    {
        return FlowDefinition::query()->where('tenant_id', $this->tenant->get()->getId())->exists();
    }

    /**
     * Writes the language matrix alone, as the setup wizard does. A locked base language is kept as stored whatever is
     * passed, so confirming the languages of a tenant that already has flows changes nothing there.
     *
     * @param  list<string>  $available
     */
    public function saveLanguages(string $base, array $available, string $fallback): void
    {
        if (! $this->baseLanguageLocked()) {
            $this->settings->content_base_language = $base;
        }

        $this->settings->available_languages = self::withBase($this->settings->content_base_language, $available);
        $this->settings->fallback_language   = $fallback;
        $this->settings->save();
    }

    /**
     * Writes the validated form. A locked base language is kept as stored whatever is passed; the request refuses a
     * change to it before this is reached.
     *
     * @param  array{content_base_language: string, available_languages: list<string>, fallback_language: string, messaging_rate_limit: int, broadcast_chunk_size: int, broadcast_backpressure: bool, conversation_logging: bool, conversation_media_fetch: bool, flow_session_ttl: int, max_retry_attempts: int, flow_fallback_message: string}  $fields
     */
    public function save(array $fields): void
    {
        if (! $this->baseLanguageLocked()) {
            $this->settings->content_base_language = $fields['content_base_language'];
        }

        $this->settings->available_languages      = self::withBase($this->settings->content_base_language, $fields['available_languages']);
        $this->settings->fallback_language        = $fields['fallback_language'];
        $this->settings->messaging_rate_limit     = $fields['messaging_rate_limit'];
        $this->settings->broadcast_chunk_size     = $fields['broadcast_chunk_size'];
        $this->settings->broadcast_backpressure   = $fields['broadcast_backpressure'];
        $this->settings->conversation_logging     = $fields['conversation_logging'];
        $this->settings->conversation_media_fetch = $fields['conversation_media_fetch'];
        $this->settings->flow_session_ttl         = $fields['flow_session_ttl'];
        $this->settings->max_retry_attempts       = $fields['max_retry_attempts'];
        $this->settings->flow_fallback_message    = $fields['flow_fallback_message'];
        $this->settings->save();
    }
}
