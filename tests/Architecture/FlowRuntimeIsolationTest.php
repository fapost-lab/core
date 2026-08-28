<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Guards the flow execution runtime — the code that runs per incoming message on a
 * long-lived Horizon worker.
 *
 * Facades and container helpers resolve through globals, so a worker that switched
 * tenant mid-job can hand a handler the wrong connection, cache store or logger.
 * Everything on this path must receive its collaborators through the constructor,
 * where the tenant-scoped container binding decides what it gets.
 *
 * Build-time flow code (drafts, publishing, validation, admin surfaces) is out of
 * scope: it runs under PHP-FPM, one request per process.
 */
final class FlowRuntimeIsolationTest
{
    public function test_flow_runtime_does_not_use_laravel_facades(): Rule
    {
        return PHPat::rule()
            ->classes(...self::runtimeSelectors())
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Illuminate\\Support\\Facades'))
            ->because('Flow runtime executes on long-lived workers; facades resolve globals that leak between tenants and jobs.');
    }

    public function test_flow_runtime_does_not_use_the_container_as_a_service_locator(): Rule
    {
        return PHPat::rule()
            ->classes(...self::runtimeSelectors())
            ->shouldNotDependOn()
            ->classes(
                Selector::classname('Illuminate\\Container\\Container'),
                Selector::classname('Illuminate\\Contracts\\Container\\Container'),
                Selector::classname('Illuminate\\Foundation\\Application'),
            )
            ->because('Hidden container lookups bypass the tenant-scoped bindings the runtime is constructed with.');
    }

    /**
     * The execution path: the engine loop, node handlers, state read/write, the
     * registries they resolve through, message routing and subflow control.
     *
     * @return list<\PHPat\Selector\SelectorInterface>
     */
    private static function runtimeSelectors(): array
    {
        return [
            Selector::classname('App\\Domains\\Flow\\Services\\FlowEngine'),
            Selector::inNamespace('App\\Domains\\Flow\\Handlers'),
            Selector::inNamespace('App\\Domains\\Flow\\Orchestration'),
            Selector::inNamespace('App\\Domains\\Flow\\Registry'),
            Selector::inNamespace('App\\Domains\\Flow\\Routing'),
            Selector::inNamespace('App\\Domains\\Flow\\State'),
            Selector::inNamespace('App\\Domains\\Flow\\Subflow'),
        ];
    }
}
