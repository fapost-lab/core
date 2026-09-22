# Node · `delay`

Pauses the session for a fixed number of seconds, then leaves through `default`.

**Type:** `delay`
**Version:** 1
**Category:** `Logic`
**Handler:** `App\Domains\Flow\Handlers\DelayNodeHandler`

## Config

```json
{
  "seconds": 60
}
```

**Fields:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `seconds` | int | no | Default `60` (schema default). Clamped to `≥ 0` at execution (`max(0, (int)($config['seconds'] ?? 60))`). |

## Output handles

- `default` — the only handle; returned once the delay has elapsed (or immediately when `seconds = 0`).

## Behavior

1. Read the per-node marker `system.delay.{nodeId}.resume_at`.
2. If it is already set (a later visit): `Carbon::now()->lt($resumeAt)` → still `waiting()`; otherwise `executed()`, clearing the marker (`{markerKey} => null`).
3. If unset (first visit): resolve `seconds` from config. `seconds = 0` → `executed()` immediately, nothing scheduled.
4. Otherwise compute `resume_at = now() + seconds`, call `DelayResumeSchedulerInterface::schedule($tenantId, $sessionId, $nodeId, $resumeAt)`, write `system.delay.{nodeId}.resume_at` and `system.delay.{nodeId}.scheduled_at` (both ISO-8601), and return `waiting()` with metadata `resume_at` and `seconds`.

Per the class docblock: the session parks in the engine's `waiting_input` status rather than a dedicated `paused` status, because routing only looks up active/waiting sessions — a `paused` session would let an inbound message start a second session for the same contact. A loop that revisits this node after its marker was cleared starts a fresh delay; scheduling twice for the same visit is harmless because the resume side only acts when `resume_at` still matches the persisted value.

## State written

- `system.delay.{nodeId}.resume_at` — ISO-8601 instant the node is allowed to complete; cleared to `null` once passed.
- `system.delay.{nodeId}.scheduled_at` — ISO-8601 instant the delay was scheduled (first visit only).

## Side effects

- `DelayResumeSchedulerInterface::schedule()` (implemented by `QueuedDelayResumeScheduler`), which is responsible for eventually invoking `ResumeDelayedFlowSessionJob` (or equivalent) to re-enter this node after `resume_at`.

## Idempotency / replay

Re-execution before `resume_at` simply stays `waiting()`; a resume that targets a `resume_at` no longer matching the persisted marker is ignored on the scheduler side (per the class docblock), so a duplicate schedule from a retried job is harmless.

## Related

- [../../../../platform/runtime/flow/10-registered-nodes-catalog.md](../../../../platform/runtime/flow/10-registered-nodes-catalog.md) — full handler catalog.
