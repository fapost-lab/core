<?php

declare(strict_types=1);

namespace App\Domains\Flow\Handlers\Support;

/**
 * Finds the template engine's placeholders that read a contact: `{{contact…}}` and `{{module…}}`.
 *
 * A message that goes to many people must not have them: in a conversation they render the run's contact, and every
 * recipient would get that person's data. Shared by `send_message` to tags or a segment and the legacy `notify`
 * contacts mode.
 */
final class ContactPlaceholders
{
    /** A `{{contact…}}` or `{{module…}}` placeholder of the template engine. */
    private const string PATTERN = '/\{\{\s*(contact|module)(\.\w+)*\s*\}\}/';

    /**
     * Whether a text, or any language of a localized one, has a `{{contact…}}` or `{{module…}}` placeholder.
     */
    public static function readsContact(mixed $value): bool
    {
        $texts = is_array($value) ? $value : [$value];

        foreach ($texts as $text) {
            if (is_string($text) && 1 === preg_match(self::PATTERN, $text)) {
                return true;
            }
        }

        return false;
    }
}
