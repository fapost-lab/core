<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use Tests\TestCase;

/**
 * Enforces ingress/worker separation: WebhookController must not depend on
 * Eloquent ORM or TenantContext — only pure Redis + job dispatch allowed in ingress.
 */
final class WebhookArchitectureTest extends TestCase
{
    public function test_webhook_controller_does_not_depend_on_eloquent(): void
    {
        $content = (string) file_get_contents(
            app_path('Domains/Webhook/Http/WebhookController.php')
        );

        $this->assertStringNotContainsString(
            'Illuminate\Database\Eloquent',
            $content,
            'WebhookController must not import Eloquent classes (ingress is DB-free).'
        );

        $this->assertStringNotContainsString(
            'use App\Domains\Contact\Models',
            $content,
            'WebhookController must not import domain models.'
        );

        $this->assertStringNotContainsString(
            'use App\Domains\Assistant\Models',
            $content,
            'WebhookController must not import domain models.'
        );
    }

    public function test_webhook_controller_does_not_depend_on_tenant_context(): void
    {
        $content = (string) file_get_contents(
            app_path('Domains/Webhook/Http/WebhookController.php')
        );

        $this->assertStringNotContainsString(
            'use App\Domains\Tenancy',
            $content,
            'WebhookController must not import Tenancy domain classes — ingress is schema-agnostic.'
        );
    }

    public function test_normalize_is_not_called_in_ingress(): void
    {
        $content = (string) file_get_contents(
            app_path('Domains/Webhook/Http/WebhookController.php')
        );

        $this->assertStringNotContainsString(
            'normalize(',
            $content,
            'normalize() must only be called from the worker (IncomingMessageJob), not ingress.'
        );

        $this->assertStringNotContainsString(
            'parseIncoming(',
            $content,
            'parseIncoming() was removed — must not appear in ingress.'
        );
    }
}
