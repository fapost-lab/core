<?php

declare(strict_types=1);

namespace App\Domains\Flow\Support;

use App\Domains\Flow\Handlers\Support\TemplateRenderer;
use Fapost\Foundation\DTO\NodeExecutionContext;
use Ramsey\Uuid\Uuid;

/**
 * Reads and resolves the `addressee` of a node that names its own recipient (`{kind: "contact", contact: "<expr>"}`):
 * `send_message` with an addressee, `start_flow`.
 *
 * The expression is rendered with the flow's engine and must give a contact id (a UUID). Anything else resolves to
 * null, never to the contact of the run: a missing id must not reach somebody else.
 */
final readonly class AddresseeResolver
{
    /** The one addressee kind so far; tags and segments come as further kinds of the same field. */
    public const string KIND_CONTACT = 'contact';

    public function __construct(
        private TemplateRenderer $templates,
    ) {
    }

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
    public static function addresseeOf(array $config): array
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
}
