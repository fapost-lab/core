<?php

declare(strict_types=1);

namespace App\Console\Commands\Flow;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Validation\FlowGraphStructureValidator;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

/**
 * Audits the graph structure of stored flows, per tenant.
 *
 * Publishing does not run {@see FlowGraphStructureValidator} yet, so a flow can be
 * active today and still fail at start (no single entry node, unreachable nodes,
 * duplicate handles). This command reports those flows without changing anything,
 * to size the problem before publish starts rejecting them. Findings never change the
 * exit code: it is a report, not a gate. It exits non-zero only when the audit itself
 * could not run: an unknown `--tenant` slug, or a tenant whose schema could not be read.
 *
 * Audited per tenant: every active published definition, and every flow's current
 * draft. Drafts with no nodes are skipped as work not yet begun.
 */
final class AuditFlowGraphCommand extends Command
{
    /** @var string */
    protected $signature = 'flow:audit-graph
        {--tenant= : Limit the audit to one tenant slug}
        {--json : Emit machine-readable JSON instead of a table}';

    /** @var string */
    protected $description = 'Reports structural graph problems in active flow definitions and drafts for every active tenant';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $tenantSwitcher,
        private readonly FlowGraphStructureValidator $validator,
    ) {
        parent::__construct();
    }

    /**
     * @throws JsonException
     */
    public function handle(): int
    {
        $tenants = $this->resolveTenants();

        if (null === $tenants) {
            return self::FAILURE;
        }

        $asJson = (bool) $this->option('json');

        /** @var list<array{tenant: string, flow_id: string, flow_name: string, source: string, code: string, path: string, message: string}> $findings */
        $findings = [];
        /** @var array<string, string> $failed */
        $failed  = [];
        $audited = 0;

        foreach ($tenants as $tenant) {
            $slug = $tenant->getSlug();

            try {
                $result = $this->tenantSwitcher->runForTenant(
                    $tenant,
                    fn (): array => $this->auditCurrentTenant($slug),
                );
            } catch (Throwable $throwable) {
                $failed[$slug] = $throwable->getMessage();

                continue;
            }

            $audited += $result['audited'];
            array_push($findings, ...$result['findings']);
        }

        $summary = [
            'tenants_scanned'    => count($tenants) - count($failed),
            'failed_tenants'     => count($failed),
            'graphs_audited'     => $audited,
            'graphs_with_issues' => count(array_unique(array_map(
                static fn (array $finding): string => "{$finding['tenant']}|{$finding['source']}|{$finding['flow_id']}",
                $findings,
            ))),
            'findings' => count($findings),
        ];

        if ($asJson) {
            $this->line(json_encode(
                ['summary' => $summary, 'failed' => $failed, 'findings' => $findings],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return [] === $failed ? self::SUCCESS : self::FAILURE;
        }

        foreach ($failed as $slug => $message) {
            $this->line("✘ {$slug}: {$message}");
        }

        if ([] !== $findings) {
            $this->table(
                ['tenant', 'flow', 'source', 'code', 'path', 'message'],
                array_map(
                    static fn (array $finding): array => [
                        $finding['tenant'],
                        "{$finding['flow_name']} ({$finding['flow_id']})",
                        $finding['source'],
                        $finding['code'],
                        $finding['path'],
                        $finding['message'],
                    ],
                    $findings,
                ),
            );
        }

        $this->info(sprintf(
            '%d graph(s) audited across %d tenant(s): %d with problems, %d finding(s)%s.',
            $summary['graphs_audited'],
            $summary['tenants_scanned'],
            $summary['graphs_with_issues'],
            $summary['findings'],
            [] === $failed ? '' : sprintf(', %d tenant(s) failed', count($failed)),
        ));

        return [] === $failed ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Runs inside the tenant switch: both tables live in the tenant schema.
     *
     * @return array{audited: int, findings: list<array{tenant: string, flow_id: string, flow_name: string, source: string, code: string, path: string, message: string}>}
     */
    private function auditCurrentTenant(string $slug): array
    {
        $audited  = 0;
        $findings = [];

        $definitions = FlowDefinition::query()
            ->active()
            ->select(['id', 'flow_id', 'name', 'nodes', 'edges'])
            ->lazyById();

        foreach ($definitions as $definition) {
            $audited++;

            foreach ($this->validator->validate($definition->nodes ?? [], $definition->edges ?? [], stripAnnotations: false) as $error) {
                $findings[] = [
                    'tenant'    => $slug,
                    'flow_id'   => (string) $definition->flow_id,
                    'flow_name' => (string) $definition->name,
                    'source'    => 'definition',
                    'code'      => $error->code,
                    'path'      => $error->path,
                    'message'   => $error->message,
                ];
            }
        }

        $drafts = FlowDraft::query()
            ->select(['id', 'flow_id', 'name', 'nodes', 'edges'])
            ->lazyById();

        foreach ($drafts as $draft) {
            if ([] === ($draft->nodes ?? [])) {
                continue;
            }

            $audited++;

            foreach ($this->validator->validate($draft->nodes, $draft->edges ?? []) as $error) {
                $findings[] = [
                    'tenant'    => $slug,
                    'flow_id'   => (string) $draft->flow_id,
                    'flow_name' => (string) $draft->name,
                    'source'    => 'draft',
                    'code'      => $error->code,
                    'path'      => $error->path,
                    'message'   => $error->message,
                ];
            }
        }

        return ['audited' => $audited, 'findings' => $findings];
    }

    /**
     * @return list<TenantInterface>|null null when the requested slug does not exist
     */
    private function resolveTenants(): ?array
    {
        $slug = $this->option('tenant');

        if (! is_string($slug) || '' === mb_trim($slug)) {
            return array_values($this->tenants->findAllActive());
        }

        $tenant = $this->tenants->findBySlug(mb_trim($slug));

        if (null === $tenant) {
            $this->error("Unknown tenant: {$slug}");

            return null;
        }

        return [$tenant];
    }
}
