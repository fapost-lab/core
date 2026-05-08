<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

/**
 * Assistant-scoped flavour of {@see TranslationOverrideRepositoryInterface}.
 * Backed by the `assistant_translations` table; scope id is `assistant_id`.
 */
interface AssistantTranslationRepositoryInterface extends TranslationOverrideRepositoryInterface
{
}
