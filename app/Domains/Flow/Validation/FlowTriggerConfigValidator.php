<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Flow\Contracts\FlowTriggerConfigValidatorInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use InvalidArgumentException;

final class FlowTriggerConfigValidator implements FlowTriggerConfigValidatorInterface
{
    public function validate(string $type, array $config): void
    {
        $triggerType = FlowTriggerType::from($type);

        match ($triggerType) {
            FlowTriggerType::Message  => $this->validateMessageConfig($config),
            FlowTriggerType::Schedule => $this->validateScheduleConfig($config),
            FlowTriggerType::Webhook  => $this->validateWebhookConfig($config),
            FlowTriggerType::Api      => $this->validateApiConfig($config),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateMessageConfig(array $config): void
    {
        $keywords = $config['keywords'] ?? null;
        $match    = $config['match'] ?? null;

        if ( ! is_array($keywords) || [] === $keywords || ! is_string($match)
            || ! in_array(
                $match,
                ['exact', 'contains', 'regex'],
                true
            )) {
            throw new InvalidArgumentException('Invalid message trigger config.');
        }

        if ('regex' !== $match) {
            return;
        }

        foreach ($keywords as $keyword) {
            if ( ! is_string($keyword) || '' === mb_trim($keyword) || false === @preg_match(mb_trim($keyword), '')) {
                throw new InvalidArgumentException('Invalid message regex trigger config.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateScheduleConfig(array $config): void
    {
        if ( ! is_string($config['cron'] ?? null) || ! is_string($config['target'] ?? null)) {
            throw new InvalidArgumentException('Invalid schedule trigger config.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateWebhookConfig(array $config): void
    {
        if ( ! is_string($config['secret'] ?? null) || ! is_string($config['method'] ?? null)) {
            throw new InvalidArgumentException('Invalid webhook trigger config.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateApiConfig(array $config): void
    {
        if ( ! is_array($config['allowed_sources'] ?? null)) {
            throw new InvalidArgumentException('Invalid api trigger config.');
        }
    }
}
