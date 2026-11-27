<?php

declare(strict_types=1);

namespace App\Domains\Flow\Statistics;

use Carbon\CarbonImmutable;

/**
 * Usage of one action (an {@see \App\Domains\Flow\Action\ActionHandlerRegistry} id, Core's or a Solution's) within one
 * tenant: the `call` nodes that send to it through the `handler` transport.
 *
 * Only a node whose target names the action as written counts; a target built from a template is known at run time
 * only, so it is attributed to no action.
 */
final readonly class ActionUsage
{
    public function __construct(
        public string $actionId,
        /** The Solution that registered the action; null for Core's own, and for an action nobody registers now. */
        public ?string $solutionId,
        /** Whether an action handler is registered under this id now. */
        public bool $registered,
        /** Active flows whose definition sends to the action. */
        public int $activeFlows,
        /** Published versions that can still run (see {@see NodeTypeUsage::$publishedVersions}) sending to it. */
        public int $publishedVersions,
        /** Executions of those nodes logged inside the runtime window. */
        public int $executions,
        /** Of those, the ones that ended on `failed`. */
        public int $failures,
        public ?CarbonImmutable $lastExecutedAt,
    ) {
    }
}
