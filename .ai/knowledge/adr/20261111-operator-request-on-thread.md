---
id: adr-20261111-operator-request-on-thread
type: adr
status: accepted
date: 2026-11-11
domains:
  - conversation
  - flow
paths:
  - app/Domains/Conversation/Services/OperatorRequests.php
  - app/Domains/Conversation/Events/OperatorRequestStateChanged.php
  - app/Domains/Flow/Handlers/RequestOperatorNodeHandler.php
  - app/Jobs/Flow/ContinueAfterOperatorRequestJob.php
  - app/Domains/Flow/Listeners/ContinueAfterOperatorRequest.php
summary: An operator request is recorded on its thread (id column + JSON body, row lock + one conditional UPDATE) and Conversation tells Flow about its outcome through an after-commit event; the node resumes via resume() with a wake-up message; the cancel button has a reserved id the router intercepts.
reviewed_at: 2026-11-11
---
# An operator request lives on its thread, and Conversation tells Flow about it through an after-commit event

## Context

The `request_operator` node (task `operator-handoff-node`, design approved at the gate 2026-10-22, reconciled
2026-11-11) parks its flow session until an operator finishes, the contact cancels, or nobody accepts in time. The
session can die meanwhile — a subflow timeout, `/reset`, a tenant command — while an operator still holds the thread,
and the "operator connected / left" texts and the cancel button must still work then. Accept and Finish happen in
Conversation (`ConversationInbox`, `ConversationOwnership`); their consequences for the contact and the session belong
to Flow. Conversation does not depend on Flow, and Flow already depends on Conversation.

## Decision

- The request is recorded on the thread: `conversations.operator_request_id` (what conditional updates match on) and
  `conversations.operator_request` (JSON body: state, waiting session and node, cancel-button message, texts already
  rendered in the contact's language). Every change locks the thread's row, computes the body from it, and writes it in
  one conditional UPDATE on the handling and the request id — never on a JSON field. `takeOver()` of a `queued` thread
  accepts its pending request; `finish()` returns an accepted one; cancel and timeout send a pending one back to the
  bot (`OperatorRequestsInterface`).
- Each change out of `pending`/`accepted` is announced as `OperatorRequestStateChanged` (`ShouldDispatchAfterCommit`).
  Conversation's listener dims the request notifications; Flow's listener queues `ContinueAfterOperatorRequestJob`,
  which under `FlowExecutionGuard` removes the button, sends the stored text, and resumes the session only while it
  still waits in that node for that request.
- The session continues through `FlowEngine::resume()` with a synthetic wake-up message; the handler reads the outcome
  from the request and returns the handle, as the `send_message` timeout does. No new engine port.
- The cancel button carries a platform-reserved id (`OperatorRequestCancelButton::ID`); `MessageRouter` recognises a
  press under the lock, before the staff/queued check, cancels the request of that session and always drops the
  message (`operator_request_cancel`).

## Alternatives

- **Keep the request only in the session state.** Rejected: the session may be killed while an operator talks, and the
  closing text and button removal would be lost.
- **Use the empty `conversations.meta`.** Rejected: a condition on an id inside JSON is spelled differently by the two
  database engines; the id needs a column of its own.
- **A port in Conversation implemented in `app/Infrastructure`.** Same dependency direction, but a contract for one
  subscriber; the event keeps the seam visible and lets Conversation react to the same moment itself.
- **A new engine port "resume a parked session on handle X"** (generalising `resumeAfterSubflow`). Rejected: it moves
  the session past the handler, against the rule that the handler chooses the source handle.

## Consequences

- A request outlives its session; the node is woken by a job, or by the contact's next message once the thread is back
  with the bot (that message is consumed by the wake-up).
- Conversation's direct imports must stay free of Flow; only review keeps it so (no PHPat rule yet).
- On a `sync` queue the timeout never fires, like every deferred resume.
- Contact texts are frozen at request time: editing the node does not change a request already waiting.
