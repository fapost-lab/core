# Node: `ask_person`

Asks a contact (the addressee the config names) a question with buttons and waits for the answer or the timeout. The
addressee is not the run's contact by default, so the node works in a contactless run (schedule, webhook, event, API)
as well as in a conversation.

**Type:** `ask_person` · **Version:** 1 · **Category:** `Conversation` · **Context:** `NodeContextRequirement::Any`
**Class:** `app/Domains/Flow/Handlers/AskPersonNodeHandler.php` · **Implements:** `DeclaresContextRequirementInterface`,
`NodeConfigValidatorInterface`

The question lives outside the session (`FlowQuestionsInterface`, implemented by `FlowQuestions`, table
`flow_questions`), so a press is recognised whatever happened to the run since. The node keeps only the question id.

## Config

```json
{
  "addressee": {"kind": "contact", "contact": "{{flow.manager_id}}"},
  "text": {"en": "Approve the order?"},
  "buttons": [
    {"id": "<uuid>", "label": {"en": "Approve"}, "value": "approve"},
    {"id": "<uuid>", "label": {"en": "Reject"}, "value": "reject"}
  ],
  "timeout_seconds": 3600,
  "late_answer_text": "",
  "save_to_variable": {"name": "decision", "type": "text", "storage": "session"}
}
```

| Field | Description |
|-------|-------------|
| `addressee` | Required. `kind` is `contact` (the only kind); `contact` is an expression that gives a contact id (`AddresseeResolver`) |
| `text` | Required. The question, translatable, a template over the session state |
| `buttons` | Required, 1 to 8 (`FlowQuestionButton::MAX_BUTTONS`). Each has a unique uuid `id` (its exit handle), a translatable `label`, and a language-agnostic `value`. The id is kept as authored and matched to an edge handle exactly, so it is case-sensitive; two ids that differ only in case are one uuid and are refused as `button_id_duplicate` |
| `timeout_seconds` | Required, 60 (`MIN_TIMEOUT_SECONDS`) to 604800 (`MAX_TIMEOUT_SECONDS`, 7 days). Default `3600` |
| `late_answer_text` | Optional text for a press after the decision. Empty gives the system text `flow.ask_person.already_decided` |
| `save_to_variable` | Optional variable that receives the pressed button's `value`. `storage: contact` writes the run's contact and is skipped in a contactless run |

`validate()` reports `addressee_required`, `addressee_kind_unknown`, `text_required`, `buttons_required`,
`buttons_too_many`, `button_id_invalid`, `button_id_duplicate`, `button_label_required` and `timeout_out_of_range`.
Labels live under `builder.nodes.ask_person.*`.

## Output handles

| Handle | When |
|--------|------|
| `{button id}` | The addressee pressed that button. An edge whose handle names no button id of the node (case included) is refused at publish as `orphaned_button_edge` |
| `timeout` | Nobody answered before the deadline, or less than a minute of the run was left, so nothing was asked |
| `error` | The addressee gave no contact id; the run has no assistant or channel to send through; the message could not be sent (including a volume limit); the question was lost; the config is invalid |

Result metadata: `answer`, `button_id`, `answered_by`, `answered_at`, or `timed_out_at`; `question_id`;
`clamped_by_run_deadline` when the wait was shortened; `error` and `error_type` on `error`.

## Behavior

State marker: `system.ask_person.{nodeId}.question_id` (`SystemStateKeys::ASK_PERSON_PREFIX`) holds the question id.

1. **Asking** (no marker). The addressee expression is resolved; no id gives `error` (`addressee_unresolved`). The
   session must have an assistant, otherwise `error` (`send_failure`).
2. **Deadline.** The wait is `timeout_seconds`. In an automation run (`FlowSession::deadline_at`, or the run kind's
   `AutomationRunDeadline`, `flow.automation.max_run_hours`, 24 h by default) it ends `DEADLINE_MARGIN_SECONDS` (60 s)
   before the run's deadline at the latest, and `clamped_by_run_deadline` is set. With no more than that left, the node
   exits `timeout` at once and records nothing (`asked: false`).
3. **Opening.** `FlowQuestions::open()` inserts the question keyed by the step's execution key (insert-or-ignore), so
   a retried step finds the same question and token. A question already out of `pending` is read, not asked again.
4. **Sending.** The question goes to the addressee through the run's assistant as inline buttons whose callback
   payload is `FlowQuestionButton::encode(token, index)`, with the idempotency key `question:{id}`. A failure closes
   the question and exits `error`.
5. **Waiting.** The node returns `delayed(resumeAt = expires_at)` with the marker, so the session parks `paused`.
   The engine schedules the wake-up; an answer wakes it earlier.
6. **Leaving.** Woken, the node reads the question: `Answered` exits by the pressed button's handle (and saves
   the value); `Expired` removes the keyboard and exits `timeout`; a `pending` question past its deadline is expired
   with one conditional UPDATE, and the loser of a race to an answer reads what won. A missing or closed question
   exits `error` (`question_lost`, warning `flow.ask_person.question_lost`). Woken before the deadline, the node keeps
   waiting.

### Pressing a button

`MessageRouter` (step 4a″) recognises a press (`FlowQuestionButton::pressedIn()`) before the operator check of step
4a, calls `FlowQuestions::receivePress()` and drops the update (`flow_question_answer`), whether the token is known
or not. A press therefore never reaches the addressee's own conversation and never starts a flow for them, and it
works while an operator holds the addressee's thread.

`receivePress()` decides only for the question's addressee, on the question's assistant, with the index of one of its
buttons; anything else changes nothing and tells nobody anything. The first answer wins: of an answer, a timeout
and a close only one moves the question out of `pending`. An accepted press stores the answer and schedules
`ContinueAfterFlowQuestionJob`; the buttons come off and no text is sent. A press after the decision, after the
timeout, or after the run ended or was reset gets the text frozen on the question, which is `late_answer_text` or the
system text, rendered in the run's language when the question was opened.

### Resuming

`ContinueAfterFlowQuestionJob` (tenant-gated, retried for an hour after it was queued) runs `FlowQuestionResumer`.
Under the run's own lock (`LockScope::ofSession()`: the contact's, or the trigger's for a contactless run) it
re-reads the session and wakes it only while it is `paused` on the question's node with the question id in the marker;
anything else is a stale continuation and a no-op. Afterwards it removes the answered question's keyboard (best effort).

### In a conversation

While the run waits, its own contact gets the usual busy reply, and `/reset` ends the run. A question is deleted with
its run (automation run retention) and with its addressee's contact.

## Tests

- `tests/Feature/Domains/Flow/AskPersonNodeTest.php`: asking, the exits, the run deadline, errors, presses that may
  not answer, a press through the router, a retried step, cascades.
- `tests/Feature/Redis/FlowQuestionResumerRedisTest.php`: the continuation under the run's lock, a busy lock.
- `tests/Unit/Domains/Flow/Routing/MessageRouterTest.php`: a press is swallowed before the operator check.
- `tests/Feature/Domains/Flow/ContactContextValidationTest.php`: publishing in a scheduled flow, config errors.
