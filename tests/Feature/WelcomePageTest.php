<?php

declare(strict_types=1);

namespace Tests\Feature;

final class WelcomePageTest extends FeatureTestCase
{
    public function test_root_serves_the_welcome_page(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertViewIs('welcome');
    }

    public function test_welcome_page_links_to_the_admin_panel(): void
    {
        // One link, deliberately: the assistant console is a Filament tenant
        // panel keyed by assistant id, so a bare /assistant link depends on how
        // many assistants the signed-in user owns.
        //
        // It points at the tenant host rather than a relative path, because this
        // page is served from the base domain where the panel does not exist.
        $response = $this->get('/');

        $response->assertSee($this->panelUrl('/admin'));
    }

    public function test_welcome_page_has_no_admin_link_in_host_mode(): void
    {
        // The base domain belongs to the platform in host mode: there is no tenant
        // of the installation whose panel it could point at.
        config(['tenancy.resolution' => 'host']);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Open the admin panel')
            ->assertDontSee('/admin');
    }

    public function test_welcome_page_is_not_indexed(): void
    {
        // An installation's root is not a public marketing page and should not
        // compete with fapost.in in search results.
        $this->get('/')->assertSee('noindex', false);
    }

    public function test_locale_switches_via_query_param(): void
    {
        $response = $this->get('/?lang=ru');

        $response->assertStatus(200);
        $response->assertSessionHas('locale', 'ru');
    }

    public function test_invalid_locale_is_ignored(): void
    {
        $response = $this->get('/?lang=xx');

        $response->assertStatus(200);
        $response->assertSessionMissing('locale', 'xx');
    }

    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => 'database/migrations', '--force' => true];
    }
}
