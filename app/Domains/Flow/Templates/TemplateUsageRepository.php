<?php

declare(strict_types=1);

namespace App\Domains\Flow\Templates;

use Illuminate\Database\ConnectionInterface;

/**
 * Read/write surface over `flow_template_usages`, the reverse index of which flows carry a copy of a template.
 * Only a host's active definition has rows: publishing the host replaces every row of its flow, and deleting the
 * host's draft forgets them, so the index always answers "where is this template placed now".
 */
final readonly class TemplateUsageRepository
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {
    }

    /**
     * @param  list<string>  $templateFlowIds
     */
    public function replaceForHost(string $hostFlowId, string $hostDefinitionId, array $templateFlowIds): void
    {
        $this->forgetHost($hostFlowId);

        $rows = [];

        foreach (array_unique($templateFlowIds) as $templateFlowId) {
            $rows[] = [
                'host_flow_id'       => $hostFlowId,
                'host_definition_id' => $hostDefinitionId,
                'template_flow_id'   => $templateFlowId,
            ];
        }

        if ([] !== $rows) {
            $this->connection->table('flow_template_usages')->insert($rows);
        }
    }

    public function forgetHost(string $hostFlowId): void
    {
        $this->connection->table('flow_template_usages')
            ->where('host_flow_id', $hostFlowId)
            ->delete();
    }

    /**
     * The flows whose active definition carries a copy of the template, ordered by flow id (the order propagation
     * locks them in).
     *
     * @return list<string>
     */
    public function hostsOf(string $templateFlowId): array
    {
        /** @var list<string> */
        return $this->connection->table('flow_template_usages')
            ->where('template_flow_id', $templateFlowId)
            ->orderBy('host_flow_id')
            ->distinct()
            ->pluck('host_flow_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }
}
