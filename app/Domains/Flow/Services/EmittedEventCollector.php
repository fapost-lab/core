<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

/**
 * Collects event names declared by `emit_event` nodes in a flow's node list.
 *
 * Used to feed the tenant-wide event registry from both draft saves and
 * publishes, so trigger pickers can reference events of any flow/assistant
 * while still preparing flows (before publishing).
 */
final class EmittedEventCollector
{
    /**
     * @param  array<int|string, mixed>  $nodes
     * @return list<string>
     */
    public function collect(array $nodes): array
    {
        $names = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || 'emit_event' !== ($node['type'] ?? null)) {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $name   = $config['event_type'] ?? $config['event_name'] ?? null;

            if (is_string($name) && '' !== mb_trim($name)) {
                $names[] = mb_trim($name);
            }
        }

        return array_values(array_unique($names));
    }
}
