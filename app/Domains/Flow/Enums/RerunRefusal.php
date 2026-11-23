<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * Why a run cannot be re-run from the start ({@see \App\Domains\Flow\Services\AutomationRunRerunService}). Its value is
 * the key of the reason the console shows (`console.flow_sessions.rerun.refusals.<value>`).
 */
enum RerunRefusal: string
{
    /** A conversation: it belongs to a contact's exchange and is never started again by a person. */
    case NotAnAutomation = 'not_an_automation';

    /** A subflow child: only the run as a whole, from its root, is re-run. */
    case NotARoot = 'not_a_root';

    /**
     * The run's status is not `failed`: it is still running, or it ended in another way — through an `end` node too,
     * whatever outcome that node set.
     */
    case NotFailed = 'not_failed';

    /** No trigger payload was kept: a run started before payloads were kept, or one no trigger fired. */
    case NoPayload = 'no_payload';

    /** The trigger that fired the run is gone or turned off, or its flow was deleted. */
    case TriggerGone = 'trigger_gone';

    /** The flow is switched off in the flows list: it starts no automatic run, a re-run included. */
    case FlowOff = 'flow_off';

    /** The run rate breaker paused the trigger: it starts no run, a re-run included, until a person resumes it. */
    case TriggerPaused = 'trigger_paused';

    /** The flow has no active version to run. */
    case NoActiveVersion = 'no_active_version';

    /**
     * The re-run the request asked for has started already, or the request is ahead of the re-runs there are: a double
     * click, a second tab or a replayed request after the first one started its run.
     */
    case AlreadyRerun = 'already_rerun';
}
