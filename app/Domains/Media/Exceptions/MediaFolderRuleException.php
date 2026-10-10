<?php

declare(strict_types=1);

namespace App\Domains\Media\Exceptions;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A folder write the tree's rules refuse: a parent or target that is not in the tenant, a folder moved into its own
 * subtree, or a tree deeper than `media.folder.max_depth`.
 *
 * Carries which field was wrong and why, not a sentence; {@see toValidationException()} words it for a form or the
 * REST API, where it is a 422 on that field.
 */
final class MediaFolderRuleException extends RuntimeException
{
    public const string NOT_FOUND = 'not_found';

    public const string PARENT_NOT_FOUND = 'parent_not_found';

    public const string OWN_SUBTREE = 'own_subtree';

    public const string TOO_DEEP = 'too_deep';

    /**
     * @param  self::*  $reason
     */
    public function __construct(
        public readonly string $attribute,
        public readonly string $reason,
        public readonly int $maxDepth = 0,
    ) {
        parent::__construct(sprintf('Media folder rule "%s" refused "%s".', $reason, $attribute));
    }

    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages([
            $this->attribute => __('media.errors.folder.' . $this->reason, ['max' => $this->maxDepth]),
        ]);
    }
}
