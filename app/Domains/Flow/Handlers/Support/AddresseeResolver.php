<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers\Support;

use Fapost\Foundation\DTO\NodeExecutionContext;
use Ramsey\Uuid\Uuid;

/**
 * The addressee a node names in its config (`{kind: "contact", contact: "<expression>"}`), shared by `send_message`
 * and `ask_person`: which one a config names, and the contact id its expression gives.
 */
final class AddresseeResolver
{
    /** The one addressee kind so far; tags and segments come as further kinds of the same field. */
    public const string KIND_CONTACT = 'contact';

    /**
     * The addressee a node's config names: its kind (null when the config names none) and its contact expression.
     *
     * No `addressee`, an empty one, or the builder's untouched default (kind `contact`, no expression) names none; a
     * kind other than `contact` is named even without an expression, so validation can refuse it.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @return array{kind: string|null, contact: string}
     */
    public static function named(array $config): array
    {
        $addressee = $config['addressee'] ?? null;

        if (! is_array($addressee)) {
            return ['kind' => null, 'contact' => ''];
        }

        $rawKind = $addressee['kind'] ?? null;
        $kind    = is_string($rawKind) && '' !== mb_trim($rawKind) ? mb_trim($rawKind) : self::KIND_CONTACT;
        $contact = is_string($addressee['contact'] ?? null) ? mb_trim($addressee['contact']) : '';

        if (self::KIND_CONTACT === $kind && '' === $contact) {
            return ['kind' => null, 'contact' => ''];
        }

        return ['kind' => $kind, 'contact' => $contact];
    }

    /**
     * The contact id the addressee's expression gives, rendered with the flow's engine; null when it gives none.
     * Never the contact of the run: a missing id must not message somebody else.
     *
     * @param  array{kind: string|null, contact: string}  $addressee
     * @param  array<string, mixed>                       $state
     */
    public static function resolve(array $addressee, TemplateRenderer $templates, NodeExecutionContext $context, array $state): ?string
    {
        if (self::KIND_CONTACT !== $addressee['kind']) {
            return null;
        }

        $rendered = $templates->render($addressee['contact'], $context, $state);
        $id       = is_scalar($rendered) ? mb_trim((string) $rendered) : '';

        return Uuid::isValid($id) ? mb_strtolower($id) : null;
    }
}
