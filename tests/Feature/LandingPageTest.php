<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\NewPresaleRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

final class LandingPageTest extends FeatureTestCase
{
    public function test_landing_page_returns_200(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertViewIs('landing');
    }

    public function test_landing_page_contains_key_sections(): void
    {
        $response = $this->get('/');

        $response->assertSee('FAPOST');
        $response->assertSee('presale-form');
    }

    public function test_locale_switches_via_query_param(): void
    {
        $response = $this->get('/?lang=ru');

        $response->assertStatus(200);
        $response->assertSessionHas('locale', 'ru');
    }

    public function test_locale_persists_in_session(): void
    {
        $this->get('/?lang=uk');
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSessionHas('locale', 'uk');
    }

    public function test_invalid_locale_is_ignored(): void
    {
        $response = $this->get('/?lang=xx');

        $response->assertStatus(200);
        $response->assertSessionMissing('locale', 'xx');
    }

    public function test_presale_store_with_valid_data(): void
    {
        Mail::fake();
        Http::fake([
            'api.hcaptcha.com/*' => Http::response(['success' => true]),
        ]);

        $response = $this->postJson('/presale', [
            'name'                 => 'John Doe',
            'company'              => 'Acme Inc',
            'email'                => 'john@example.com',
            'messenger_preference' => 'telegram',
            'message'              => 'Interested in the platform',
            'h-captcha-response'   => 'test-token',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('presale_requests', [
            'name'                 => 'John Doe',
            'company'              => 'Acme Inc',
            'email'                => 'john@example.com',
            'messenger_preference' => 'telegram',
        ]);

        Mail::assertQueued(NewPresaleRequest::class);
    }

    public function test_presale_store_requires_name(): void
    {
        Http::fake();

        $response = $this->postJson('/presale', [
            'company'              => 'Acme Inc',
            'email'                => 'john@example.com',
            'messenger_preference' => 'telegram',
            'h-captcha-response'   => 'test-token',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('name');
    }

    public function test_presale_store_requires_valid_email(): void
    {
        Http::fake();

        $response = $this->postJson('/presale', [
            'name'                 => 'John Doe',
            'company'              => 'Acme Inc',
            'email'                => 'not-an-email',
            'messenger_preference' => 'telegram',
            'h-captcha-response'   => 'test-token',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('email');
    }

    public function test_presale_store_requires_valid_messenger_preference(): void
    {
        Http::fake();

        $response = $this->postJson('/presale', [
            'name'                 => 'John Doe',
            'company'              => 'Acme Inc',
            'email'                => 'john@example.com',
            'messenger_preference' => 'invalid',
            'h-captcha-response'   => 'test-token',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('messenger_preference');
    }

    public function test_presale_store_requires_captcha(): void
    {
        $response = $this->postJson('/presale', [
            'name'                 => 'John Doe',
            'company'              => 'Acme Inc',
            'email'                => 'john@example.com',
            'messenger_preference' => 'telegram',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('h-captcha-response');
    }

    public function test_presale_store_fails_on_captcha_verification_failure(): void
    {
        Http::fake([
            'api.hcaptcha.com/*' => Http::response(['success' => false]),
        ]);

        $response = $this->postJson('/presale', [
            'name'                 => 'John Doe',
            'company'              => 'Acme Inc',
            'email'                => 'john@example.com',
            'messenger_preference' => 'telegram',
            'h-captcha-response'   => 'bad-token',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('h-captcha-response');
    }

    public function test_presale_store_message_is_optional(): void
    {
        Mail::fake();
        Http::fake([
            'api.hcaptcha.com/*' => Http::response(['success' => true]),
        ]);

        $response = $this->postJson('/presale', [
            'name'                 => 'Jane Doe',
            'company'              => 'Test Corp',
            'email'                => 'jane@example.com',
            'messenger_preference' => 'both',
            'h-captcha-response'   => 'test-token',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('presale_requests', [
            'email'   => 'jane@example.com',
            'message' => null,
        ]);
    }

    /**
     * Run root-level migrations only (presale_requests, cache, jobs).
     * Landlord setup is handled by {@see FeatureTestCase::setUpLandlord()}.
     *
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => 'database/migrations', '--force' => true];
    }
}
