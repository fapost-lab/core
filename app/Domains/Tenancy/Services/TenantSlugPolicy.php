<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Database\TenantDatabaseManager;
use App\Domains\Tenancy\Exceptions\InvalidTenantSlugException;
use Illuminate\Support\Str;

/**
 * Decides whether a tenant may claim a given slug.
 *
 * A slug is not just a database key: on a subdomain deployment it becomes a
 * hostname the tenant controls. Two things follow, and both are enforced here.
 *
 * It must be a valid DNS label, or it cannot be served at all — and, more subtly,
 * an unconstrained slug can collide after the schema name is derived from it:
 * "web-hook" and "web_hook" both become tenant_web_hook, and because the derivation
 * collapses runs of separators, "web--hook" would too. Underscores and consecutive
 * hyphens are therefore rejected.
 *
 * And it must not name a host the platform itself serves. A tenant answering on
 * the ingress hostname would receive other tenants' webhooks, whose headers carry
 * their channel secrets. Reserving those names is a security boundary, not tidiness.
 *
 * Finally, the schema name must fit PostgreSQL's 63-byte identifier limit. The server
 * truncates longer names silently, so two long slugs sharing a prefix would map to one
 * physical schema while the application still sees them as different. The slug length
 * is therefore bounded here, next to the derivation it protects.
 */
final readonly class TenantSlugPolicy
{
    /** Prefix of every tenant schema name. */
    public const string SCHEMA_PREFIX = 'tenant_';

    /** The schema name is a PostgreSQL identifier, which is silently truncated past this length. */
    public const int MAX_SCHEMA_NAME_BYTES = TenantDatabaseManager::MAX_IDENTIFIER_BYTES;

    /**
     * Longest slug whose schema name still fits. Must equal MAX_SCHEMA_NAME_BYTES minus
     * strlen(SCHEMA_PREFIX) (7); strlen() is not allowed in a constant expression on PHP 8.4,
     * so a unit test pins the two together.
     */
    public const int MAX_SLUG_LENGTH = self::MAX_SCHEMA_NAME_BYTES - 7;

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

        if (mb_strlen($slug) > self::MAX_SLUG_LENGTH) {
            throw InvalidTenantSlugException::tooLong($slug, self::MAX_SLUG_LENGTH, self::MAX_SCHEMA_NAME_BYTES);
        }

        if (str_starts_with($slug, self::PUNYCODE_PREFIX)) {
            throw InvalidTenantSlugException::punycodePrefix($slug);
        }

        if (str_contains($slug, '--')) {
            throw InvalidTenantSlugException::consecutiveHyphens($slug);
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

    /**
     * Derives the PostgreSQL schema name for a slug.
     *
     * Only assignable slugs yield a name within {@see self::MAX_SCHEMA_NAME_BYTES}.
     */
    public function schemaNameFor(string $slug): string
    {
        return self::SCHEMA_PREFIX . Str::slug($slug, '_');
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
