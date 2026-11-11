# Node: `request_operator`

Asks for a human operator and waits for the outcome. The conversation thread goes from the assistant to
`queued` ("waiting for an operator"), the operators are notified, the contact is told, and the session parks in the
node until the request ends.

**Type:** `request_operator` · **Version:** 1 · **Category:** `Conversation`
**Class:** `app/Domains/Flow/Handlers/RequestOperatorNodeHandler.php` · **Enums:** `StaffNotifyTarget`
(`assistant`, `role`, `users`), `OperatorRequestState`, `OperatorRequestPlacementOutcome`

The request lives on the conversation thread (Conversation's `OperatorRequestsInterface`, implemented by
`OperatorRequests`), not in the session, so it outlives the session. The node keeps only the request id.

## Config

```json
{
  "target": "assistant",
  "notify_by_email": false,
  "operator_text": "",
  "wait_seconds": 300,
  "requested_text": {"en": "Looking for an operator for you."},
  "cancel_button": true,
  "cancel_label": {"en": "Cancel request"},
  "connected_text": "",
  "disconnected_text": {"en": "The operator has left the chat."}
}
```

| Field | Description |
|-------|-------------|
| `target` | `StaffNotifyTarget`: `assistant` (default), `role`, `users`; an unknown value falls back to `assistant` |
| `role` | A `RoleEnum` value, for `target = role` |
| `user_ids` | Explicit staff user ids, for `target = users` |
| `notify_by_email` | Also email the operators; the bell notification is always sent. Default `false` |
| `operator_text` | Optional template for the operators (admin-UI language, as in `notify`). Empty gives the standard "Contact X is waiting for an operator" |
| `wait_seconds` | How long to wait for an operator to accept. Default `300` (`DEFAULT_WAIT_SECONDS`); `0` waits without a limit |
| `requested_text` | "Request sent", sent to the contact when the request is placed |
| `cancel_button` | Attach the "Cancel request" inline button. Default `true`; it is shown only where the channel has `ChannelCapability::CallbackButtons` and `cancel_label` is not empty |
| `cancel_label` | The button's label; shown when `cancel_button` is on |
| `connected_text` | Optional "operator connected", sent when an operator accepts |
| `disconnected_text` | "Operator left", sent when the operator finishes and returns the thread to the assistant |

The four contact texts are in the contact's content language (`ContentTranslatorInterface::resolveField`, so a
localized `{lang: text}` map works) and are templates over the session state. They are rendered **when the request
is placed** and stored on the request; the later texts are sent from that copy. An empty text is not sent.

The default config comes from `configSchema()`; the labels live under `builder.nodes.request_operator.*`.

## Output handles

| Handle | When |
|--------|------|
| `returned` | The operator finished. "Operator left" is sent first, then the flow continues |
| `cancelled` | The contact pressed the cancel button (also when the request is no longer found, which is not expected) |
| `timeout` | Nobody accepted within `wait_seconds`, or the request could not be placed |

There is no `default` handle; an unwired exit ends the session.

## Behavior

State marker: `system.request_operator.{nodeId}` (`SystemStateKeys::REQUEST_OPERATOR_PREFIX`) holds the request id.

1. **Placing.** With no marker (or a marker whose request has ended, as in a loop), the node calls
   `OperatorRequests::place()`:
   - the tenant's conversation log is disabled (`LoggingDisabled`) or the contact has no active channel (`NoChannel`):
     no request, a warning `flow.request_operator.not_placed` is logged, and the node exits `timeout` at once
     (metadata `placed: false`). Without a log an operator would see an empty thread;
   - otherwise the thread is found or created, and, if the bot answers it, moved to `queued` with a new request
     (`Placed`). A `NotifyOperatorRequestJob` is dispatched.
2. **Already waiting or handled.** If the thread already waits for an operator, or an operator already handles it,
   the request is bound to this session and node (`Bound`): nobody is notified again, no "request sent" text goes
   out and no timeout is scheduled. The session waits for that state; finishing exits `returned`.
3. **Telling the contact** (`Placed` only). "Request sent" goes out, with the cancel button when the channel allows,
   and the message id is attached to the request (`attachMessage`). If the request stopped waiting while the message
   was on its way, the button is removed at once. A failure to send is logged and leaves the request waiting.
4. **Timeout.** With `wait_seconds > 0`, `OperatorRequestTimeoutSchedulerInterface` schedules an
   `ExpireOperatorRequestJob` (`QueuedOperatorRequestTimeoutScheduler`) for that moment. It needs an asynchronous
   queue: on a `sync` queue it never fires, the same known limit as `send_message` timeouts.
5. **Waiting.** The node returns `waiting` with the marker. A re-run while its request still waits does nothing.
6. **Leaving.** When the node is woken by an incoming message it reads the state of the request: `Returned`,
   `Cancelled` and `Timeout` map to the handles above; `Pending` and `Accepted` keep it waiting.

### Operators and notifications

`NotifyOperatorRequestJob` (queue `messaging.system`, inside the tenant switch) resolves the recipients with
`StaffRecipientResolver` from the node's `target`, `role` and `user_ids`, then narrows them with
`ConversationOperators::whoCanTake`: only staff who can view and reply to conversations **and** have access to the
assistant. If none remain, the administrators who may. Each gets a bell notification linking to the thread, and an
email when `notify_by_email` is on (one failed mail does not fail the job). A request that stopped waiting before the
job ran notifies nobody.

When an operator accepts (the inbox's Accept or Take over), the request notifications are dimmed for everyone
(marked read, showing "Accepted by Y"); cancel and timeout dim them too.

### Resuming

Every state change of the request dispatches `ContinueAfterOperatorRequestJob` (queue `flow.execution`). Under the
session lock of the contact at the assistant it:

1. removes the cancel button once the request stops being pending;
2. sends "operator connected" on `accepted` and "operator left" on `returned`, from the texts stored on the request;
3. on a final state, resumes the session with a synthetic `IncomingMessage` (`updateId`
   `operator_request:{id}:{state}`), only while the session still waits in this node for this request (marker equals
   the request id). A session that ended meanwhile is not continued; the thread has simply gone back to the bot.

### Messages while waiting, commands and the cancel button

`MessageRouter` (step 4a) keeps the bot silent while the thread is queued or held by an operator: contact messages
go to the inbox only. The cancel button carries the reserved id `OperatorRequestCancelButton::ID`; a press is
recognised before routing and calls `OperatorRequests::cancelForSession()`, and the update is dropped
(`operator_request_cancel`).

`GlobalCommandExecutor` cancels the pending request of the session it ends: `/reset`, `/cancel` and tenant commands
that start a flow. The thread goes back to the assistant, the notifications are dimmed, the button is removed, and the
ended session does not continue. Informational commands do not, and while an operator handles the thread commands do
not affect it.

### Idempotency

The request id keys everything that must happen once: the operator notifications (a cache guard on
`(tenant, request)`, 24 h), and the contact texts through the send idempotency key `{requestId}:requested`,
`{requestId}:accepted` and `{requestId}:returned`. A retried node or job therefore never notifies or announces twice.

### Reuse

The node is self-contained. To reuse a configured request, put it in a subflow and map the exits with `end` nodes:
`returned` to `success`, `cancelled` to `cancelled`, `timeout` to `failed`. The parent cannot tell that `failed` from
the subflow's own error or timeout. Give the subflow a timeout longer than an operator conversation (hours, for
example `P1D`), otherwise the subflow sweeper ends the child while the operator may still be talking, and the parent
continues via `failed`. Flow outbound messages are not blocked while an operator holds the thread.

## Tests

- `tests/Feature/Domains/Flow/RequestOperatorNodeHandlerTest.php` — placing, binding, waiting, the three exits, the cancel button.
- `tests/Feature/Domains/Conversation/OperatorRequestsTest.php` — the request on the thread: place, bind, accept, return, cancel, expire, races.
- `tests/Feature/Domains/Flow/ContinueAfterOperatorRequestJobTest.php` — texts, button removal and resume after a state change; the timeout job.
- `tests/Feature/Domains/Conversation/ConversationOperatorNotificationTest.php` — recipients, email, dimming on the outcome.
- `tests/Unit/Domains/Flow/Routing/MessageRouterTest.php`, `tests/Feature/Domains/Flow/GlobalCommandExecutorOperatorRequestTest.php` — cancel by button and by command.
