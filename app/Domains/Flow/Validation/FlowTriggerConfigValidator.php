<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Flow\Contracts\FlowTriggerConfigValidatorInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Events\EventTriggerAssistantFilter;
use App\Domains\Flow\Registry\TriggerTypeRegistry;
use App\Domains\Flow\Webhook\WebhookTriggerConfig;
use Fapost\Foundation\Flow\Enums\NodeConfigIssueSeverity;
use InvalidArgumentException;

/**
 * A built-in type's config is checked here; a registered type's by the type itself ({@see TriggerTypeRegistry}), its
 * first error refusing the config. A dotted type nobody registers passes: it is a Solution's type, and its row may
 * outlive the Solution (publishing refuses it with `trigger_type_not_installed` before anything is saved). Any other
 * type is unknown and refused.
 */
final readonly class FlowTriggerConfigValidator implements FlowTriggerConfigValidatorInterface
{
    public function __construct(
        private TriggerTypeRegistry $triggerTypes,
    ) {
    }

    public function validate(string $type, array $config): void
    {
        $triggerType = FlowTriggerType::tryFrom($type);

        if (null === $triggerType) {
            $this->validateRegisteredConfig($type, $config);

            return;
        }

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
    private function validateRegisteredConfig(string $type, array $config): void
    {
        if (! $this->triggerTypes->has($type)) {
            if (str_contains($type, '.')) {
                return;
            }

            throw new InvalidArgumentException("Unknown trigger type \"{$type}\".");
        }

        foreach ($this->triggerTypes->get($type)->validateConfig($config) as $issue) {
            if (NodeConfigIssueSeverity::Error === $issue->severity) {
                throw new InvalidArgumentException($issue->message);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateMessageConfig(array $config): void
    {
        $keywords = $config['keywords'] ?? null;
        $phrases  = $config['phrases'] ?? null;

        if (! is_array($keywords) || (null !== $phrases && ! is_array($phrases))
            || [] === $this->filterStringList($keywords, is_array($phrases) ? $phrases : [])) {
            throw new InvalidArgumentException('Invalid message trigger config.');
        }
    }

    /**
     * @param  array<int, mixed>  $keywords
     * @param  array<int, mixed>  $phrases
     *
     * @return list<string>
     */
    private function filterStringList(array $keywords, array $phrases): array
    {
        $values = [];

        foreach ([...$keywords, ...$phrases] as $value) {
            if (! is_string($value) || '' === mb_trim($value)) {
                continue;
            }

            $values[] = mb_trim($value);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateScheduleConfig(array $config): void
    {
        $target = $config['timezone'] ?? $config['target'] ?? null;

        if (! is_string($config['cron'] ?? null) || '' === mb_trim($config['cron'])
             || ! is_string($target)
             || '' === mb_trim($target)) {
            throw new InvalidArgumentException('Invalid schedule trigger config.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateWebhookConfig(array $config): void
    {
        WebhookTriggerConfig::fromConfig($config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function validateApiConfig(array $config): void
    {
        $routeKey = $config['route_key'] ?? null;

        if ((null !== $routeKey && (! is_string($routeKey) || '' === mb_trim($routeKey)))
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

        // Must accept the SAME shape emit_event produces (dotted, mixed-case
        // segments — see ValidateFlowService::validateEmitEventConfig), otherwise
        // a dotted emitted name like `order.created` could never match a trigger.
        if (! is_string($eventName) || 1 !== preg_match('/^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)*$/', $eventName)) {
            throw new InvalidArgumentException('Invalid event trigger config.');
        }

        $assistantFilter = $config[EventTriggerAssistantFilter::CONFIG_KEY] ?? null;

        if (null === $assistantFilter) {
            return;
        }

        if (! is_array($assistantFilter) || ! array_is_list($assistantFilter)) {
            throw new InvalidArgumentException('Invalid event trigger assistant filter.');
        }

        foreach ($assistantFilter as $assistantId) {
            if (! is_string($assistantId) || '' === mb_trim($assistantId)) {
                throw new InvalidArgumentException('Invalid event trigger assistant filter.');
            }
        }
    }
}
