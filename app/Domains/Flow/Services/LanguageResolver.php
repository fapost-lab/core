<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Contracts\LanguageResolverInterface;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Tenancy\Settings\TenantSettings;

final readonly class LanguageResolver implements LanguageResolverInterface
{
    public function __construct(
        private TenantSettings $tenantSettings,
    ) {
    }

    public function resolve(Contact $contact, FlowSession $session): string
    {
        $sessionLanguage = data_get($session->state, SystemStateKeys::LANGUAGE);

        if (is_string($sessionLanguage) && '' !== $sessionLanguage) {
            return $sessionLanguage;
        }

        if (is_string($contact->language) && '' !== $contact->language) {
            return $contact->language;
        }

        return $this->tenantSettings->fallback_language;
    }
}
