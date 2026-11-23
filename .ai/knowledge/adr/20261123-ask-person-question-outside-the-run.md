---
id: adr-20261123-ask-person-question-outside-the-run
type: adr
status: proposed
date: 2026-11-23
domains:
  - flow
paths:
  - app/Domains/Flow/Handlers/AskPersonNodeHandler.php
  - app/Domains/Flow/Services/FlowQuestions.php
  - app/Domains/Flow/Support/FlowQuestionButton.php
  - app/Domains/Flow/Orchestration/FlowQuestionResumer.php
  - app/Jobs/Flow/ContinueAfterFlowQuestionJob.php
  - app/Domains/Flow/Routing/MessageRouter.php
summary: An ask_person question lives in flow_questions outside the run (conditional UPDATEs, random token), its q1 button payload is always swallowed by the router before the operator check, and the run waits on a delayed(resumeAt) park capped one minute before an automation's deadline
---
# An `ask_person` question is recorded outside the run, answered by a `q1` button payload the router always swallows, and waits on a `delayed(resumeAt)` park capped by the run's deadline

## Context

Spec `flow-automations`, phase 5, "Ask a person and wait": a run (often contactless — schedule, webhook, event) must
ask a person a question with buttons and continue by the answer, with a timeout. A press of a button is in effect the
right to decide for the run, whose next nodes may have side effects in other systems, and a sent question cannot be
recalled. Until now a callback button carried `CallbackDataCodec` (session id + button id, 64 hex) and a press reached
`FlowOrchestrator`, which resumes the *presser's* active session or starts one: an answer to somebody else's run would
have been eaten by the addressee's own conversation. The press arrives under the addressee's session lock, while the run
lives under another (its trigger's or another contact's). Gate of task `flow-ask-person-and-wait`, approved by the owner
("Да, всё по рекомендациям", 2026-11-23): the recommended option of each decision D-1…D-6.

## Decision

- **A new node type, `ask_person@1`** (D-1), not a waiting `send_message` with an addressee: the latter's wait is bound
  to its own contact's session. It declares `any` and names its recipient (`addressee`, `{kind: contact, contact:
  <expression>}`, shared with `send_message` through `Handlers/Support/AddresseeResolver`).
- **The question lives outside the session**, in tenant table `flow_questions` (`FlowQuestionsInterface`,
  `Services/FlowQuestions`): addressee, assistant, buttons (id, value), `expires_at`, status `pending → answered |
  expired | closed`, a 128-bit random `token`, the late-answer text frozen in the run's language. It is opened
  insert-or-get by `(session_id, node_id, execution_key)` so a retried step finds the same question and sends nothing
  twice; every move out of `pending` is one conditional UPDATE. It cascades with its session and its addressee contact.
  The node keeps only its id in `system.ask_person.{nodeId}.question_id` (whitelisted `system.*` writer).
- **Button payload `q1` + token + 2-hex index** (D-4, 36 bytes), passed to `FlowMessageSender` as the button's
  `callback_payload`; an author's `send_message` button can never set one. `MessageRouter` swallows every
  `callback_query` in this format — known token or not — after the operator-cancel button and **before** the
  "thread with an operator" check (`flow_question_answer` drop). Only the addressee, on the question's assistant, with
  an index of its buttons, decides; anything else changes nothing and tells nobody anything. A press while the question
  is pending, not past `expires_at`, and its run is live at the node is recorded as the answer and a
  `ContinueAfterFlowQuestionJob` is queued after commit; a press when the run is over or elsewhere closes the question;
  every late press gets the late-answer text (D-5: the node's, or system key `flow.ask_person.already_decided`). An
  accepted press sends no text; the job removes the keyboard.
- **The wait is the engine's `delayed(resumeAt: expires_at)` park** (`paused`): the timeout is the ordinary delayed
  wake-up, and the answer wakes it earlier through `Orchestration/FlowQuestionResumer`, which runs the session under
  `LockScope::ofSession()` only while it is `paused` at the node with that question's marker. Woken, the node reads the
  outcome from the question: the button's handle (its id), `timeout` (expiring it if still pending; losing that race
  reads the answer), or `error` (`question_lost`); every exit clears the marker.
- **Timeout bounds** (D-6): required, 60 s … 7 days. **In an automation run** (D-3) the wait ends at
  `deadline_at − 60 s`; with less than that left the node takes `timeout` at once without asking
  (`clamped_by_run_deadline`). The run's deadline invariant is unchanged.
- **Conversations** (D-2): allowed; while the run waits its own contact gets the usual busy reply, `/reset` ends it.

## Alternatives

- Lifting `addressee_cannot_wait` on `send_message` — mixes two wait mechanisms in a handler every conversation uses.
- Allowing the node only in contactless flows — safer, but a one-line validation to lift later; the owner chose to allow it.
- Refusing at publish a timeout longer than `max_run_hours`, or not counting a parked run against its deadline — the
  first fails publishes for a setting the author does not see, the second changes a flow invariant.
- Reusing the 64-hex codec for question buttons — an unknown token would fall through as input to the addressee's
  conversation.
- A text reply ("answer accepted") on every press — noise; Core has no toast.

## Consequences

- A new stage on the path of every inbound message: a regex on a `callback_query` text, a query only on a match.
- A tap that comes while the addressee's own session holds the lock is dropped by the lock timeout like any message;
  the question then times out (known limit).
- Rolling the change back must keep a router stage swallowing `q1…` presses until the last question expired (≤ 7
  days), else old presses reach the addressee's conversation as input.
- A wait longer than a day in an automation needs `flow.automation.max_run_hours` raised.
- A run without an assistant cannot ask (`error`), until "global" triggers settle where such a run sends from.
