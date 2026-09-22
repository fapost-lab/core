---
id: convention-queues
type: convention
status: active
domains: []
paths:
  - "app/Jobs/**"
  - config/horizon.php
  - config/queue.php
  - "app/Domains/Broadcasting/**"
  - "app/Domains/Messaging/**"
summary: Queues are split by purpose (flow.execution, messaging.*, scheduled.triggers, sync.external); rate limits and backpressure are preventive, not reactive.
---
# Messaging and queues

<!-- A stable engineering practice: what we do, one example, and the reason it holds. -->

## Practice

Queues are not mixed by purpose. Each queue name carries exactly the kind of work its name
says:

```
flow.execution        - inbound processing and the execution pipeline
messaging.transactional - replies in an active dialogue
messaging.broadcast    - low-priority broadcasts / fan-out
messaging.system       - service notifications
messaging.logging      - conversation capture, written outside the delivery path
scheduled.triggers     - scheduled/event trigger fan-out
sync.external          - external synchronisations
```

A job sets its queue explicitly with `$this->onQueue('...')` (or `->onQueue()` on the pending
dispatch) in its constructor — never the Horizon `default` queue for real work. Provider rate
limits and backpressure are preventive: a job that fans out to an external provider checks and
throttles before it sends, it does not wait to react to a provider error.

`sync.external` is reserved on purpose: Horizon's `medium` supervisor in
`config/horizon.php` already listens on it and the published docs describe it, but no job
dispatches to it yet. Keep the supervisor listening and the queue name reserved rather than
repurposing it for something else.

## Example

Queue assignment by purpose, as it exists in the codebase today:

- `flow.execution`: `app/Jobs/Flow/ResumeDelayedFlowSessionJob.php`,
  `app/Jobs/Flow/ResumeTimedOutSendMessageNodeJob.php`, and the webhook controller's initial
  dispatch (`app/Domains/Webhook/Http/WebhookController.php`).
- `messaging.transactional`: `app/Jobs/Messaging/SendTransactionalMessageJob.php`.
- `messaging.broadcast`: `app/Domains/Broadcasting/Jobs/RunBroadcastJob.php` and
  `SendBroadcastRecipientJob.php`.
- `messaging.system`: `app/Domains/Staff/Jobs/SendStaffNotificationJob.php`,
  `app/Jobs/Media/CleanupSoftDeletedMediaJob.php`.
- `messaging.logging`: `app/Domains/Conversation/Jobs/PersistConversationMessageJob.php`.
- `scheduled.triggers`: `app/Jobs/Flow/StartFlowFromEventJob.php`.

Preventive backpressure: `RunBroadcastJob` counts the depth of `messaging.broadcast`
(`Queue::size()`) before creating any recipient rows and, above
`BACKPRESSURE_QUEUE_THRESHOLD`, re-dispatches itself with a delay instead of enqueueing a
large fan-out on top of an already-deep queue — the check runs before work is committed, not
after a provider starts rejecting sends.

`config/horizon.php`'s `defaults` groups queues into `high`/`medium`/`low` supervisors by
latency sensitivity (`flow.execution` and `messaging.transactional` on `high`;
`sync.external` and `scheduled.triggers` on `medium`; the broadcast/system/logging queues on
`low`), which is a capacity policy layered on top of the purpose split above, not a
replacement for it.

## Rationale

`tests/Architecture/MessagingBoundariesTest.php` enforces that the Flow domain does not depend
on messaging provider implementations, but the queue-per-purpose split itself is review-only —
there is no test that a job landed on the "right" queue name. Mixing purposes on one queue lets
a burst of low-priority broadcast fan-out delay a transactional reply, or lets a slow external
sync starve inbound flow execution, because Horizon balances within a queue, not across
unrelated work sharing one. Naming the queue after its purpose keeps that a capacity-planning
decision (how many processes on `high`) instead of a correctness one (which job blocks which).
