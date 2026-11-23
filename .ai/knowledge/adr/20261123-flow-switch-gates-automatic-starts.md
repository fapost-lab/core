---
id: adr-20261123-flow-switch-gates-automatic-starts
type: adr
status: accepted
date: 2026-11-23
domains:
  - flow
paths:
  - app/Domains/Flow/Orchestration/AutomationRunner.php
  - app/Domains/Flow/Schedule/ScheduleTriggerFirer.php
  - app/Jobs/Flow/StartFlowFromEventJob.php
  - app/Domains/Flow/Webhook/FlowWebhookReceiver.php
  - app/Domains/Flow/Services/AutomationRunRerunService.php
  - app/Domains/Flow/Repositories/FlowDraftRepository.php
---
# A flow switched off in the flows list starts no automatic run: every automatic start path asks the draft's switch before run admission

## Context

The flows list has an on/off switch per flow (`flow_drafts.is_active`). Turning it on with a live automatic trigger
needs `ManageAutomations` (`FlowDraftPolicy::activate()`), and the docs said a switched-off flow is started by nothing.
In fact no start path read it: schedules, webhooks, Core events, flow events, Solution trigger types and re-runs all
started runs of a switched-off flow, so the permission guarded a decorative switch (finding F10 of the
flow-automation-permissions review). Approved at the design gate by the owner ("Да, все по рекомендациям",
2026-11-23: the recommended option of every gate question).

## Decision

- **One reader.** `FlowDraftRepositoryInterface::isSwitchedOff(flowId)` is true only when the flow's draft exists and
  `is_active` is false. A missing draft is not "off": each path already treats it as it did before.
- **Every automatic start path asks it before run admission, and after deduplication:**
  - `AutomationRunner::start()` (webhook, Core event, Solution trigger type, re-run) — after `findByFireKey`, so a fire
    that already has a run (a schedule's `pending` run claimed while the flow was on, a retried start) goes on; then
    `AutomationStartResult::flowOff()` and the log `flow.automation.start_skipped_flow_off`;
  - `ScheduleTriggerFirer`'s claim — after `flow_not_live`, before `previous_run_live` and admission: the slot is spent
    (`tick_skipped`, `flow_off`), so turning the flow on starts only its next slot;
  - `StartFlowFromEventJob` — before admission (`flow.event.start_skipped_flow_off`);
  - `FlowWebhookReceiver` — its own branch after the signature check and before the pause check: 404, the delivery
    not claimed in the dedup, the per-address miss limiter not hit (`flow.webhook.flow_off`);
  - `AutomationRunRerunService::check()` — `RerunRefusal::FlowOff`, shown in the console like the other refusals.
- **Nothing is caught up.** Slots, webhook deliveries answered 404 and event starts dropped while the flow is off are
  not replayed when it is turned on; a sender that retries a 404 later is a new delivery of the sender's.
- **Conversations are out of scope.** Message triggers, `/start`, the default flow, subflows and `start_flow` targets
  still start a switched-off flow; the published docs say so. Gating them is a decision of its own (its blast radius is
  bots going silent).

## Alternatives

- **A step 0 in `TriggerRunAdmission` (`RefusalReason::FlowOff`)** — one place, but a switched-off schedule would be
  counted as a refusal on its trigger every minute and shown in the builder, mixing "a person turned it off" with
  "a limit refused it"; the webhook would still need its own early branch. Rejected.
- **Inside `findLatestActiveByFlowId()`** — the same method serves messages, `/start`, subflows, `start_flow` and the
  default flow: it would silently widen the change to conversations. Rejected.
- **Writing the switch into `flow_triggers.is_active`** — only the publish sync and the schedule claim write that row;
  the next publish would overwrite it, and turning the flow on would bring back what was not published. Rejected.
- **Filtering switched-off flows out of the schedule's due triggers** — the slot would stay due, and turning the flow
  on within five minutes would run a slot that came due while it was off. Rejected.
- **Webhook answers** 503 with `Retry-After` (the sender's retries after the switch-on would catch up) and 202 with
  the delivery claimed (the sender believes it was accepted: a silent loss). Rejected for 404, the documented "no
  live trigger here".

## Consequences

- A new automatic start path must ask `isSwitchedOff` itself (flow `RULES.md`); tests on each path enforce it.
- A switched-off flow spends no unit of the plan, trips no breaker and fills no refusal counter.
- At deploy, flows switched off long ago whose automatic triggers kept running stop firing; the release notes say so.
- `git revert` restores the old behaviour with no data to migrate; what was skipped while a flow was off stays skipped.
- A `sync` webhook accepted just before the switch-off waits out its timeout and gets 202 when the runner drops its
  start, as for an admission refusal.
