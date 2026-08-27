<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Services\LanguageResolver;
use App\Domains\Flow\State\SystemStateKeys;
use App\Domains\Tenancy\Settings\TenantSettings;
use ReflectionClass;
use Tests\TestCase;

final class LanguageResolverTest extends TestCase
{
    public function test_it_prefers_system_language_from_session_state(): void
    {
        $resolver = new LanguageResolver($this->settings('en', 'fr'));

        $resolved = $resolver->resolve(
            contact: $this->contact('de'),
            session: new FlowSession(['state' => ['system' => ['language' => 'es']]]),
        );

        $this->assertSame('es', $resolved);
    }

    public function test_it_falls_back_to_contact_language_when_session_override_is_missing(): void
    {
        $resolver = new LanguageResolver($this->settings('en', 'fr'));

        $resolved = $resolver->resolve(
            contact: $this->contact('de'),
            session: new FlowSession(['state' => ['system' => []]]),
        );

        $this->assertSame('de', $resolved);
    }

    public function test_it_falls_back_to_tenant_fallback_language_when_contact_language_is_empty(): void
    {
        $resolver = new LanguageResolver($this->settings('en', 'fr'));

        $resolved = $resolver->resolve(
            contact: $this->contact(''),
            session: new FlowSession(['state' => [SystemStateKeys::LANGUAGE => null]]),
        );

        $this->assertSame('fr', $resolved);
    }

    private function settings(string $base, string $fallback): TenantSettings
    {
        $settings                        = (new ReflectionClass(TenantSettings::class))->newInstanceWithoutConstructor();
        $settings->content_base_language = $base;
        $settings->fallback_language     = $fallback;

        return $settings;
    }

    private function contact(string $language): Contact
    {
        return new Contact([
            'tenant_id'   => 'tenant-1',
            'platform'    => PlatformEnum::Telegram,
            'external_id' => 'external-1',
            'language'    => $language,
            'meta'        => [],
            'attributes'  => [],
        ]);
    }
}
