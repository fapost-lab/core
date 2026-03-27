<?php

declare(strict_types=1);

namespace Tests\Feature;

final class ExampleTest extends FeatureTestCase
{
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
    /**
     * @return array<string, mixed>
     */
    protected function migrateFreshUsing(): array
    {
        return ['--path' => 'database/migrations', '--force' => true];
    }
}
