# 07. Node Cookbook (practical recipes)

This file is about "how to do it", not only "how it works". Each recipe names the closest
**registered** Core node to read as a real example. Registered types are listed in
[10-registered-nodes-catalog.md](10-registered-nodes-catalog.md); any other type name below is a
hypothetical vendor node and is marked so.

---

## Recipe A: A simple sync node (transform / set)

### When to use

- compute a value and write it to `flow.*` or the contact,
- no external HTTP calls,
- no waiting for an inbound event.

### Behaviour

- `execute()` always finishes the node in one pass,
- returns `NodeExecutionResult::executed(sourceHandle: 'default')`.

### Minimal contract

- input from `state` / `config`,
- output in `stateChanges` (or through `ContactWriter`),
- deterministic: the same input gives the same output.

### Real example

`AssignNodeHandler` (`assign`): evaluates its `operations[]` and writes each into session state or,
for the contact storage, through `ContactWriter`. Hypothetical vendor examples of the same shape:
`format_date`, `normalize_phone` (not registered).

---

## Recipe B: An input-like node (wait for the user's reply)

### When to use

- stop the flow and wait for a message from the user.

### Behaviour

1. If `context->incoming` is empty, return `Waiting`.
2. If there is input, validate and normalise it.
3. Return `Executed` with a `sourceHandle` (`default` / `invalid` per the node's model).

### Notes

- If the node can receive the same update twice, guard against duplicates.
- If there is a retry limit, keep the counter in state (`system.*`; `input` uses
  `SystemStateKeys::INPUT_RETRY_PREFIX`).

### Real example

`InputNodeHandler` (`input`). A confirmation step through a callback button is `send_message` with
inline buttons, where each button id is a handle.

---

## Recipe C: A branch node

### When to use

- pick one of several branches by rules.

### Behaviour

- resolve the operand,
- match it against the rules in order,
- return the matching rule's handle, otherwise `default`.

### Design advice

- Always keep a fallback handle (`default`) so the flow does not stop silently.
- Put the actual values used for the decision into `logResolved`.
- A branch handler must not touch the database layer; read `module.*` through the data accessor
  (enforced by `HandlerVersionContractTest`).

### Real example

`BranchNodeHandler` (`branch`). The legacy `condition` and `switch` types are not registered.

---

## Recipe D: An external call node

### When to use

- call an external service synchronously.

### Behaviour

- on success: `Executed` with `sourceHandle = 'success'`,
- on a controlled error: `Executed` with `sourceHandle = 'error'` (the business branch),
- on an uncontrolled error: an exception, which the engine turns into a failed session.

### Guardrails

- a timeout on the HTTP request,
- explicit handling of non-2xx responses,
- an idempotent external call (the engine-provided key, safe retries).

### Real example

`CallNodeHandler` (`call`) over a pluggable transport (`http` or `handler`); it routes on `success` /
`error`. A CRM-specific call such as `crm_create_lead` would be a hypothetical vendor node (not
registered) built as a `call` action rather than a new transport.

---

## Recipe E: An async node (delay / pause / resume)

### When to use

- pause for a timer and continue later.

### Behaviour

1. First run: record the schedule in state, schedule the resume, return `Waiting` (or
   `Delayed` with `resumeAt`; see section 6 of the development guide).
2. Re-run: if already scheduled, do not schedule again; return `Waiting` until the time is up.
3. The continuation is triggered by an external job or event.

### Key risks

- double scheduling,
- endless waiting without a resume mechanism,
- races between concurrent retries.

### Real example

`DelayNodeHandler` (`delay`) with `ResumeDelayedFlowSessionJob` and `DelayedSessionResumer`. A
`send_message` timeout transition is the same pattern through `SendMessageTimeoutSchedulerInterface`.

---

## Recipe F: A message-sender node (like `send_message`)

### When to use

- the node sends content to the user.

### Behaviour

- builds the payload from config, state and language,
- sends through `MessageSenderInterface`,
- records a sent marker in `system.sent_messages`,
- on a re-run checks the marker and does not send again.

### Required

- a stable idempotency key,
- multilingual support when text or labels are localisable,
- separate "build payload" from "send".

---

## Recipe G: A node that changes the contact

### When to use

- change canonical contact data (`language`, attributes, tags).

### Do it right

- call `ContactWriterInterface` (`context->contactWriter`) directly from the handler. It is the
  single channel for `contact.*` mutations; the legacy `effects[]` is gone.
- do not write to the `Contact` model around the writer.

### Examples

- `AssignNodeHandler` and `AuthRequestNodeHandler` write contact fields through the writer
  (`auth_request` raises `contacts.is_authenticated`).
- `SetTagNodeHandler` changes tags through `ContactTagRepositoryInterface`, because tags live in a
  separate table rather than in `contact.*`.

### Why

- the handler's dependency on the writer is explicit in its context,
- easy to test: fake the writer directly.

---

## Recipe H: Migrating a node from v1 to v2

### When to make v2

- the meaning of `config` changes,
- handles or the transition contract change,
- old flows might behave differently.

### Plan

1. Keep the v1 handler in the registry.
2. Add a v2 handler (same `type`, different `version`) and register it too.
3. The builder creates new nodes with v2 (`NodeHandlerRegistry::all()` returns the latest version).
4. Do not touch old definitions automatically.
5. Prepare a flow migration tool separately if needed.

---

## A pre-code design checklist

- What are the `type` and the node's responsibility?
- Which `sourceHandle`s can it return?
- Which `config` fields are required?
- What goes into `stateChanges`?
- Are contact mutations needed through `ContactWriter`?
- What happens on a retry of the same message or job?
- Where is the negative path (error / invalid / timeout)?

With an answer to each before implementation, a node usually fits in without pain.
