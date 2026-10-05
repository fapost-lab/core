<?php

declare(strict_types=1);

namespace App\Domains\Flow\Nodes;

use Fapost\Support\Builder\Schema\Fields\TextareaField;
use Fapost\Support\Builder\Schema\Schema;

/**
 * Builder-only annotation node types — visual notes on the canvas with no
 * runtime behaviour and no NodeHandler. They live in the draft for the builder
 * to render, are skipped by validation, and are stripped from the published
 * definition so the engine never encounters them.
 */
final class AnnotationNodeTypes
{
    public const string COMMENT = 'comment';

    /**
     * Config key holding the note body of a comment node.
     */
    public const string COMMENT_TEXT = 'text';

    /**
     * @var list<string>
     */
    public const array TYPES = [self::COMMENT];

    public static function isAnnotation(mixed $type): bool
    {
        return is_string($type) && in_array($type, self::TYPES, true);
    }

    /**
     * Remove annotation nodes (comment) so the published definition contains
     * only executable graph, bridging the chain across each one.
     *
     * A comment dropped between two steps is a note, not a cut: every edge
     * pointing at it is re-pointed at whatever it continued into (following a
     * run of consecutive comments), keeping its own handle. Comments with no
     * continuation simply lose their incoming edges.
     *
     * @param  array<int, mixed>  $nodes
     * @param  array<int, mixed>  $edges
     * @return array{0: array<int, mixed>, 1: array<int, mixed>}
     */
    public static function strip(array $nodes, array $edges): array
    {
        $annotationIds = [];

        foreach ($nodes as $node) {
            if (is_array($node) && self::isAnnotation($node['type'] ?? null)) {
                $id = is_string($node['id'] ?? null) ? $node['id'] : null;
                if (null !== $id) {
                    $annotationIds[$id] = true;
                }
            }
        }

        if ([] === $annotationIds) {
            return [$nodes, $edges];
        }

        $nodes = array_values(array_filter(
            $nodes,
            static fn ($node): bool => ! (is_array($node) && self::isAnnotation($node['type'] ?? null)),
        ));

        $bridged = [];

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            // Edges leaving an annotation exist only to carry the chain through
            // it; the incoming side below inherits their target.
            if (isset($annotationIds[$edge['from'] ?? null])) {
                continue;
            }

            if (! isset($annotationIds[$edge['to'] ?? null])) {
                $bridged[] = $edge;

                continue;
            }

            $target = self::resolveAnnotationSuccessor((string)$edge['to'], $edges, $annotationIds);

            if (null === $target) {
                continue;
            }

            $edge['to'] = $target;
            $bridged[]  = $edge;
        }

        return [$nodes, array_values($bridged)];
    }

    /**
     * Builder config schema for the comment node. Annotation nodes have no
     * NodeHandler, so their schema lives here and is surfaced by
     * `NodeTypesController` alongside the registry entries.
     *
     * @return array<string, mixed>
     */
    public static function commentConfigSchema(): array
    {
        return Schema::make()
            ->fields([
                TextareaField::make(self::COMMENT_TEXT)
                    ->label('Note')
                    ->placeholder('Why this part of the flow looks the way it does…')
                    ->help('Visible in the builder only — comments are stripped from the published flow.')
                    ->withoutVariablePicker(),
            ])
            ->toArray();
    }

    /**
     * First executable node reachable from an annotation along `default` edges,
     * or null when the run of annotations ends the chain.
     *
     * @param  array<int, mixed>       $edges
     * @param  array<string, true>     $annotationIds
     */
    private static function resolveAnnotationSuccessor(string $annotationId, array $edges, array $annotationIds): ?string
    {
        $seen    = [];
        $current = $annotationId;

        while (! isset($seen[$current])) {
            $seen[$current] = true;

            $next = null;

            foreach ($edges as $edge) {
                if (! is_array($edge) || ($edge['from'] ?? null) !== $current) {
                    continue;
                }

                if ('default' !== ($edge['handle'] ?? 'default')) {
                    continue;
                }

                $next = is_string($edge['to'] ?? null) ? $edge['to'] : null;

                break;
            }

            if (null === $next) {
                return null;
            }

            if (! isset($annotationIds[$next])) {
                return $next;
            }

            $current = $next;
        }

        return null;
    }
}
