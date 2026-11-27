<?php

declare(strict_types=1);

namespace App\Domains\Flow\Subflow;

use App\Domains\Flow\Handlers\SubflowNodeHandler;

/**
 * Reads a subflow node's optional `flow_version`: the published version of the child flow the node is pinned to.
 * Absent, null or an empty string means no pin, and the node runs the child's latest active version (ADR-11).
 */
final class SubflowPin
{
    public const string FIELD = 'flow_version';

    /**
     * The pinned version: null when the node is not pinned, false when the value is not a positive whole number.
     *
     * @param  array<string, mixed>  $config  the subflow node's config
     */
    public static function read(array $config): int|false|null
    {
        $raw = $config[self::FIELD] ?? null;

        if (null === $raw || '' === $raw) {
            return null;
        }

        if (is_string($raw) && 1 === preg_match('/^\s*\d+\s*$/', $raw)) {
            $raw = (int) $raw;
        }

        if (is_float($raw) && floor($raw) === $raw) {
            $raw = (int) $raw;
        }

        return is_int($raw) && $raw >= 1 ? $raw : false;
    }

    /**
     * The child flow and version a node pins: only a subflow node with a static `flow_id` and a valid pin has one. The
     * publish check and the flow version retention both read pins through here, so a version one of them sees as
     * pinned is pinned for the other too.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function target(mixed $node): ?array
    {
        if (! is_array($node) || SubflowNodeHandler::TYPE !== ($node['type'] ?? null) || ! is_array($node['config'] ?? null)) {
            return null;
        }

        $flowId  = is_string($node['config']['flow_id'] ?? null) ? mb_trim($node['config']['flow_id']) : '';
        $version = self::read($node['config']);

        return '' !== $flowId && ! str_contains($flowId, '{{') && is_int($version) ? [$flowId, $version] : null;
    }
}
