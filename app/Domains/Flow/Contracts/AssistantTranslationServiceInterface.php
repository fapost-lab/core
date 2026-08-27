<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

/**
 * Assistant-scoped flavour of {@see TranslationOverrideServiceInterface} —
 * write side for `assistant_translations`.
 */
interface AssistantTranslationServiceInterface extends TranslationOverrideServiceInterface
{
}
