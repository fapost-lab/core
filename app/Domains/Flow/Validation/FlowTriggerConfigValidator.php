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
            FlowTriggerType::Event    => $this->validateEventConfig($config),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateMessageConfig(array $config): void
    {
        $keywords = $config['keywords'] ?? null;
        $phrases  = $config['phrases'] ?? null;

        if ( ! is_array($keywords) || (null !== $phrases && ! is_array($phrases))
            || [] === $this->filterStringList($keywords, is_array($phrases) ? $phrases : [])) {
            throw new InvalidArgumentException('Invalid message trigger config.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateScheduleConfig(array $config): void
    {
        $target = $config['timezone'] ?? $config['target'] ?? null;

        if ( ! is_string($config['cron'] ?? null) || '' === mb_trim($config['cron'])
            || ! is_string($target) || '' === mb_trim($target)) {
            throw new InvalidArgumentException('Invalid schedule trigger config.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateWebhookConfig(array $config): void
    {
        $method = $config['method'] ?? null;
        $path   = $config['path'] ?? null;
        $secret = $config['secret'] ?? null;

        if ( ! is_string($method) || ! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
            || ! is_string($secret) || '' === mb_trim($secret)) {
            throw new InvalidArgumentException('Invalid webhook trigger config.');
        }

        if (null !== $path && ( ! is_string($path) || '' === mb_trim($path))) {
            throw new InvalidArgumentException('Invalid webhook trigger config.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateApiConfig(array $config): void
    {
        $routeKey = $config['route_key'] ?? null;

        if ((null !== $routeKey && ( ! is_string($routeKey) || '' === mb_trim($routeKey)))
            || ! is_array($config['allowed_sources'] ?? null)) {
            throw new InvalidArgumentException('Invalid api trigger config.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateEventConfig(array $config): void
    {
        $eventName = $config['event_name'] ?? null;

        if ( ! is_string($eventName) || 1 !== preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $eventName)) {
            throw new InvalidArgumentException('Invalid event trigger config.');
        }
    }

    /**
     * @param  array<int, mixed>  $keywords
     * @param  array<int, mixed>  $phrases
     * @return list<string>
     */
    private function filterStringList(array $keywords, array $phrases): array
    {
        $values = [];

        foreach ([...$keywords, ...$phrases] as $value) {
            if ( ! is_string($value) || '' === mb_trim($value)) {
                continue;
            }

            $values[] = mb_trim($value);
        }

        return $values;
    }
}
