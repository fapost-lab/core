<?php

declare(strict_types=1);

namespace Tests\Feature\Tma;

use Tests\Feature\FeatureTestCase;

final class TmaRoutesTest extends FeatureTestCase
{
    public function test_tma_spa_catch_all_route_returns_tma_view(): void
    {
        $response = $this->get('/tma/form/example-form');

        $response->assertOk();
        $response->assertSee('<div id="app"></div>', false);
        $response->assertSee('telegram-web-app.js');
    }

    public function test_tma_api_show_returns_stub_form_definition(): void
    {
        $response = $this->getJson('/tma/api/forms/form-123');

        $response->assertOk();
        $response->assertJsonPath('form_id', 'form-123');
        $response->assertJsonPath('fields.0.type', 'text');
        $response->assertJsonPath('fields.1.type', 'select');
        $response->assertJsonPath('fields.2.type', 'checkbox');
    }

    public function test_tma_api_submit_accepts_answers_payload(): void
    {
        $response = $this->postJson('/tma/api/forms/form-123/submit', [
            'answers' => [
                'f1' => 'Jane Doe',
                'f2' => 'Engineering',
                'f3' => true,
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'accepted');
        $response->assertJsonPath('received.answers.f1', 'Jane Doe');
        $response->assertJsonPath('received.answers.f3', true);
    }

    public function test_tma_api_is_unauthorized_in_production_even_with_header(): void
    {
        $this->app->instance('env', 'production');

        $response = $this->getJson('/tma/api/forms/form-123', [
            'X-Telegram-Init-Data' => 'garbage',
        ]);

        $response->assertUnauthorized();
        $response->assertJsonPath('message', 'Telegram initData verification is not implemented.');
    }
}
