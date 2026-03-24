<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class () extends SettingsMigration {
    public function up(): void
    {
        $this->migrator->add('tenant.messaging_rate_limit', 30);
        $this->migrator->add('tenant.broadcast_chunk_size', 100);
        $this->migrator->add('tenant.broadcast_backpressure', true);
        $this->migrator->add('tenant.flow_session_ttl', 86400);
        $this->migrator->add('tenant.max_retry_attempts', 3);
        $this->migrator->add('tenant.flow_fallback_message', 'Произошла ошибка. Попробуйте позже.');
        $this->migrator->add('tenant.webhook_timeout', 10);
        $this->migrator->add('tenant.webhook_rate_limit', 60);
        $this->migrator->add('tenant.max_contacts', 0);
    }
};
