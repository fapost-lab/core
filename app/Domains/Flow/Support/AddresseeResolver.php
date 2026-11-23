<?php

declare(strict_types=1);

namespace App\Domains\Flow\Support;

/**
 * Reads the `addressee` a node's config names: whom the node reaches instead of the contact of the run.
 *
 * `{kind: "contact", contact: "<expression>"}` is one person, the contact id the expression gives; `{kind: "tags",
 * tags: [...]}` and `{kind: "segment", segment_id: "..."}` are an audience, reached through a broadcast. No `addressee`,
 * an empty one, or the builder's untouched default (kind `contact`, no expression) names none. A kind this class does
 * not know is still named, so validation can refuse it.
 *
 * Pure and static: the same config always reads the same.
 */
final class AddresseeResolver
{
    public const string KIND_CONTACT = 'contact';
    public const string KIND_TAGS    = 'tags';
    public const string KIND_SEGMENT = 'segment';

    /** @var list<string> */
    public const array KINDS = [self::KIND_CONTACT, self::KIND_TAGS, self::KIND_SEGMENT];

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
