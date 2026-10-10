<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Settings\TenantSettings;

/**
 * The tenant's settings screen in the admin panel: the language matrix (content base, available, fallback), the
 * messaging limit, the flow runtime defaults and the broadcast pacing — every field of {@see TenantSettings} except
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
     * The values the form starts from.
     *
     * @return array{content_base_language: string, available_languages: list<string>, fallback_language: string, messaging_rate_limit: int, broadcast_chunk_size: int, broadcast_backpressure: bool, flow_session_ttl: int, max_retry_attempts: int, flow_fallback_message: string}
     */
    public function state(): array
    {
        return [
            'content_base_language'  => $this->settings->content_base_language,
            'available_languages'    => array_values($this->settings->available_languages),
            'fallback_language'      => $this->settings->fallback_language,
            'messaging_rate_limit'   => $this->settings->messaging_rate_limit,
            'broadcast_chunk_size'   => $this->settings->broadcast_chunk_size,
            'broadcast_backpressure' => $this->settings->broadcast_backpressure,
            'flow_session_ttl'       => $this->settings->flow_session_ttl,
            'max_retry_attempts'     => $this->settings->max_retry_attempts,
            'flow_fallback_message'  => $this->settings->flow_fallback_message,
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
     * Writes the validated form. A locked base language is kept as stored whatever is passed; the request refuses a
     * change to it before this is reached.
     *
     * @param  array{content_base_language: string, available_languages: list<string>, fallback_language: string, messaging_rate_limit: int, broadcast_chunk_size: int, broadcast_backpressure: bool, flow_session_ttl: int, max_retry_attempts: int, flow_fallback_message: string}  $fields
     */
    public function save(array $fields): void
    {
        if (! $this->baseLanguageLocked()) {
            $this->settings->content_base_language = $fields['content_base_language'];
        }

        $this->settings->available_languages    = self::languageList($fields['available_languages']);
        $this->settings->fallback_language      = $fields['fallback_language'];
        $this->settings->messaging_rate_limit   = $fields['messaging_rate_limit'];
        $this->settings->broadcast_chunk_size   = $fields['broadcast_chunk_size'];
        $this->settings->broadcast_backpressure = $fields['broadcast_backpressure'];
        $this->settings->flow_session_ttl       = $fields['flow_session_ttl'];
        $this->settings->max_retry_attempts     = $fields['max_retry_attempts'];
        $this->settings->flow_fallback_message  = $fields['flow_fallback_message'];
        $this->settings->save();
    }
}
