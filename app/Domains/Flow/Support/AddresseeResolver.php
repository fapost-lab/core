<?php

declare(strict_types=1);

namespace App\Domains\Flow\Support;

use App\Domains\Flow\Enums\AudienceKind;
use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Ramsey\Uuid\Uuid;

/**
 * Reads and resolves the `addressee` a node's config names: whom the node reaches instead of the contact of the run.
 *
 * `{kind: "contact", contact: "<expression>"}` is one person, the contact id the expression gives; `{kind: "tags",
 * tags: [...]}` and `{kind: "segment", segment_id: "..."}` are an audience, reached through a broadcast. No `addressee`,
 * an empty one, or the builder's untouched default (kind `contact`, no expression) names none. A kind this class does
 * not know is still named, so validation can refuse it.
 *
 * A contact expression is rendered with the flow's engine and must give a contact id (a UUID). Anything else resolves
 * to null, never to the contact of the run: a missing id must not reach somebody else.
 */
final readonly class AddresseeResolver
{
    public const string KIND_CONTACT = 'contact';

    /** The audience kinds are {@see AudienceKind} values, so the audience path can always map one to a broadcast. */
    public const string KIND_TAGS    = AudienceKind::Tags->value;
    public const string KIND_SEGMENT = AudienceKind::Segment->value;

    /** @var list<string> */
    public const array KINDS = [self::KIND_CONTACT, self::KIND_TAGS, self::KIND_SEGMENT];

    public function __construct(
        private TemplateRenderer $templates,
    ) {
    }

    /**
     * @param  array<array-key, mixed>  $config  the node's `config`
     *
     * @return array{kind: string|null, contact: string, tags: list<string>, segment_id: string}
     */
    public static function addresseeOf(array $config): array
    {
        return self::parse($config);
    }

    /**
     * @param  array<array-key, mixed>  $config  the node's `config`
     *
     * @return array{kind: string|null, contact: string, tags: list<string>, segment_id: string}
     */
    public static function parse(array $config): array
    {
        $none      = ['kind' => null, 'contact' => '', 'tags' => [], 'segment_id' => ''];
        $addressee = $config['addressee'] ?? null;

        if (! is_array($addressee)) {
            return $none;
        }

        $rawKind = $addressee['kind'] ?? null;
        $kind    = is_string($rawKind) && '' !== mb_trim($rawKind) ? mb_trim($rawKind) : self::KIND_CONTACT;
        $contact = is_string($addressee['contact'] ?? null) ? mb_trim($addressee['contact']) : '';

        if (self::KIND_CONTACT === $kind && '' === $contact) {
            return $none;
        }

        return [
            'kind'       => $kind,
            'contact'    => $contact,
            'tags'       => self::tags($addressee['tags'] ?? null),
            'segment_id' => is_string($addressee['segment_id'] ?? null) ? mb_trim($addressee['segment_id']) : '',
        ];
    }

    /**
     * Whether the kind is an audience (tags or a segment), reached through a broadcast rather than one message.
     */
    public static function isAudience(?string $kind): bool
    {
        return self::KIND_TAGS === $kind || self::KIND_SEGMENT === $kind;
    }

    /**
     * Whether `addressee.tags` is a non-empty list of non-empty strings, as a tags audience needs.
     *
     * @param  array<array-key, mixed>  $config
     */
    public static function hasValidTags(array $config): bool
    {
        $raw = $config['addressee']['tags'] ?? null;

        if (! is_array($raw) || [] === $raw || ! array_is_list($raw)) {
            return false;
        }

        foreach ($raw as $tag) {
            if (! is_string($tag) || '' === mb_trim($tag)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The contact id the addressee's expression gives, lower-cased; null when it gives none or the kind is unknown.
     *
     * @param  array{kind: string|null, contact: string}  $addressee
     * @param  array<string, mixed>                       $state
     */
    public function resolve(array $addressee, NodeExecutionContext $context, array $state): ?string
    {
        if (self::KIND_CONTACT !== $addressee['kind']) {
            return null;
        }

        $rendered = $this->templates->render($addressee['contact'], $context, $state);
        $id       = is_scalar($rendered) ? mb_trim((string) $rendered) : '';

        return Uuid::isValid($id) ? mb_strtolower($id) : null;
    }

    /**
     * The distinct, trimmed, non-empty tags, in order.
     *
     * @return list<string>
     */
    private static function tags(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $tags = [];

        foreach ($raw as $tag) {
            $tag = is_string($tag) ? mb_trim($tag) : '';

            if ('' !== $tag && ! in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }
}
