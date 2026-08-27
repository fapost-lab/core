<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\AssistantTranslationRepositoryInterface;
use App\Domains\Flow\Contracts\AssistantTranslationServiceInterface;
use App\Domains\Flow\Contracts\ContentTranslatorInterface;

final readonly class AssistantTranslationService implements AssistantTranslationServiceInterface
{
    public function __construct(
        private AssistantTranslationRepositoryInterface $translations,
        private ContentTranslatorInterface $translator,
    ) {
    }

    public function upsert(string $scopeId, string $key, string $language, string $value): void
    {
        $this->translations->upsert($scopeId, $key, $language, $value);
        $this->translator->invalidateAssistant($scopeId, $language);
    }

    public function delete(string $scopeId, string $key, string $language): void
    {
        $this->translations->delete($scopeId, $key, $language);
        $this->translator->invalidateAssistant($scopeId, $language);
    }
}
