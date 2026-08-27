<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Exceptions\InvalidTenantSlugException;

/**
 * Decides whether a tenant may claim a given slug.
 *
 * A slug is not just a database key: on a subdomain deployment it becomes a
 * hostname the tenant controls. Two things follow, and both are enforced here.
 *
 * It must be a valid DNS label, or it cannot be served at all — and, more subtly,
 * an unconstrained slug can collide after the schema name is derived from it:
 * "web-hook" and "web_hook" both become tenant_web_hook.
 *
 * And it must not name a host the platform itself serves. A tenant answering on
 * the ingress hostname would receive other tenants' webhooks, whose headers carry
 * their channel secrets. Reserving those names is a security boundary, not tidiness.
 */
final readonly class TenantSlugPolicy
{
    /** RFC 1035 label: alphanumeric with inner hyphens, at most 63 characters. */
    private const string LABEL_PATTERN = '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/';

    /** Prefix reserved for punycode; allowing it invites homograph lookalikes of real hosts. */
    private const string PUNYCODE_PREFIX = 'xn--';

    /**
     * @param  list<string>  $reserved  Slugs the platform claims, from config and from its own ingress hostnames.
     */
    public function __construct(
        private array $reserved = [],
    ) {
    }

    /**
     * @throws InvalidTenantSlugException
     */
    public function assertAssignable(string $slug): void
    {
        if (! $this->isWellFormed($slug)) {
            throw InvalidTenantSlugException::malformed($slug);
        }

        if (str_starts_with($slug, self::PUNYCODE_PREFIX)) {
            throw InvalidTenantSlugException::punycodePrefix($slug);
        }

        if ($this->isReserved($slug)) {
            throw InvalidTenantSlugException::reserved($slug);
        }
    }

    /**
     * Whether the slug is a servable DNS label.
     *
     * Case is not normalized: accepting "WebHook" and silently storing "webhook"
     * would make the stored identity differ from what the caller asked for.
     */
    public function isWellFormed(string $slug): bool
    {
        return 1 === preg_match(self::LABEL_PATTERN, $slug);
    }

    public function isReserved(string $slug): bool
    {
        return in_array(mb_strtolower($slug), $this->reserved, true);
    }

    /**
     * @return list<string>
     */
    public function reservedSlugs(): array
    {
        return $this->reserved;
    }
}
