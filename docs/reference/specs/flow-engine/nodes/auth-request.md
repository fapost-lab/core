# Node: `auth_request`

A Core flag-setter that marks the contact authenticated inside a flow.

**Type:** `auth_request` · **Versions:** 1 and 2 · **Category:** `Contact`
**Classes:** `app/Domains/Flow/Handlers/AuthRequestNodeHandler.php` (`@1`),
`app/Domains/Flow/Handlers/AuthRequestV2NodeHandler.php` (`@2`, the one the builder adds)

Version 2 adds the ownership checks and two outputs; it is described in its own section below. Everything up to
that section describes version 1, which stays registered unchanged for the nodes published with it.

It is a Core node, not a Feature-level one: there is no `app/Features/` and no activation machinery for it.
The node is **not a brancher**: it has a single `default` output, sets the flag when the check passes and
continues either way.

## Challenge types (`AuthMethod`)

The node is challenge-typed through the `AuthMethod` enum (`app/Domains/Flow/Enums/AuthMethod.php`). Version 1
runs only `basic`, whatever `method` says.

`basic` compares a variable against an expected value with the shared operator set (`BranchOperator`:
`eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `contains`, `in`, `empty`, `not_empty`). On a match the node raises the
canonical flag **`contacts.is_authenticated`**.

## Config

```json
{
  "method": "basic",
  "variable": "flow.code",
  "operator": "eq",
  "value": "1234"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `method` | enum | yes | `basic` (default and only value) |
| `variable` | state path | for `basic` | The operand to check, for example `flow.code`. A structured `left` (the operand picker format used by `branch`) wins when present |
| `operator` | enum | no | `BranchOperator`, default `eq` |
| `value` | template string | no | Expected value, rendered through `TemplateRenderer` |

The UI is the override `AuthRequestConfig.vue`: `ConditionOperandPicker` (as in `branch`) plus localised
operators (`builder.operators.*`). Operand and comparison logic is shared with `branch` through
`Handlers/Support/OperandResolver` and `OperatorComparator`.

## Output handles

- `default`: always. The node returns `Executed` with the `default` handle whether the check passed or not.

Metadata: `method` and `authenticated` (whether the check passed). `logResolved` holds the resolved operand.

## Behavior

1. Resolve the operand (structured `left`, else the `variable` path).
2. Render the expected `value` and compare it with the operator.
3. If it passed, write `contact.is_authenticated = true` through `ContactWriterInterface`. Writing `true`
   twice is a no-op, so the node is safe to retry. If no writer is available the node throws
   `InvalidNodeConfigException`.
4. Return `Executed` with `default`.

Infrastructure: the migration `add_is_authenticated_to_contacts_table` (a boolean column on `contacts`, like
`language`, not inside `attributes`), the `Contact` cast, `ContactWriter::WRITABLE_COLUMNS` including
`is_authenticated`, and `is_authenticated` in the canonical column list of `ScopedStateReader`, so a `branch`
reading it does not look into the JSONB and get `null`.

## The auth mechanism has three parts

1. **Setting the flag:** this node.
2. **Checking the flag:** no dedicated node; use `branch` with a `user_variable` operand (storage `contact`,
   name `is_authenticated`).
3. **Flow availability (the gate):** `flow_definitions.is_public` (a mirror of `flow_drafts.is_public`, default
   true; `PublishFlowService` copies it from the draft; the UI toggle is "Public" in the console's flow form).
   `FlowAccessPolicy::canStart(definition, contact)` is `is_public || contact.is_authenticated`, and fails open
   for a `null` value. `FlowOrchestrator` applies it when it would start a **new** session: a private flow for an
   unauthenticated contact does not start and falls through to the normal fallback, as with "no flow available".
   Resuming an existing session, subflows and persistent-button re-entry are not gated.

## Tests

`tests/Feature/Domains/Flow/AuthRequestNodeHandlerTest.php`, `tests/Unit/Domains/Flow/FlowAccessPolicyTest.php`,
the `ContactWriterTest` canonical-column case, and `ScopedStateReaderTest`.

## Version 2

Methods: `basic` (as above, without waiting), `phone`, `sms_code`, `email_code`. Outputs: `authenticated` (the flag
is raised) and `failed`. Each entry into the node is a numbered challenge: `system.auth.<node>` holds `active`, `challenge`, `attempts` and,
for a code method, `sends` and `last_sent_at`; on exit only `challenge` is kept, so the next entry is `n + 1` and its
sends (`<challenge>:<send>`) key their own stored code, job guard and quota unit.

Metadata: `method`, `outcome` (`authenticated`, `failed`, `waiting`), `reason`
(`mismatch`, `wrong_code`, `attempts_exhausted`, `expired`, `delivery_failed`, `rate_limited`, `foreign_contact`,
`phone_mismatch`, `phone_invalid`, `contact_required`, `invalid_destination`, `no_sender`, `cooldown`), `detail`
(`max_sends`, `destination`, `quota`, or the last reason before `attempts_exhausted`), `attempts`, `sends`,
`destination_masked` (`+38050***67`, `j***@mail.com`). Never the code or a full address.

| Field | Methods | Description |
|---|---|---|
| `prompt` (localizable) | phone, sms, email | The message that asks the contact; required at publish |
| `button_label` (localizable) | phone | The share button; required at publish (the prompt's text if a translation is missing) |
| `expected_phone` (template) | phone | When set, the shared number must equal it in E.164 form |
| `destination` (template) | sms, email | Where the code goes; SMS defaults to `{{contact.phone}}`, email has no default and is required |
| `code_length` | sms, email | 4–8, default 6 |
| `ttl_minutes` | sms, email | 1–30, default 10 |
| `max_attempts` | phone, sms, email | 1–10, default 5 (3 for phone) |
| `max_sends` | sms, email | 1–5, default 3 |
| `resend_cooldown_seconds` | sms, email | 30–600, default 60 |
| `resend_label` (localizable) | sms, email | The "new code" inline button; without it there is no button |
| `on_invalid_message` (localizable) | phone, sms, email | Sent after a wrong answer while attempts remain |

**Phone.** The first pass sends the prompt with a `request_contact` reply button and waits; a message that brought
the flow to the node is not an answer. A `Contact` message whose card `user_id` equals the sender passes (normalised
to E.164, compared with `expected_phone` when set); a forwarded card (`foreign_contact`), a text or an invalid number
costs an attempt and asks again. `IncomingMessageJob` already stores the contact's own number in `meta.phone`.

**Codes.** The first pass checks the destination, the channel's sender, the per-destination cap (5 codes an hour per
tenant and address, `AuthCodeStore::DESTINATION_LIMIT`) and the per-period quota (`auth_code_sms`,
`auth_code_email`, `RefusedWork::Other`), generates the code, keeps its HMAC in `AuthCodeStore` and queues
`SendOneTimeCodeJob` (encrypted, `messaging.system`, one attempt behind a guard per send), then sends the prompt.
Only a text answer is an attempt (another message type is ignored). It is reduced to digits and compared in constant
time: a match raises the flag and marks the code used (a retried step still matches; the next challenge replaces it);
a mismatch costs an attempt; a missing record (expired, used, lost by the cache) is `expired`; a send the job reported
undelivered is `delivery_failed`. The resend button issues send `n + 1` after the cooldown and replaces the code;
pressed earlier it is ignored, pressed past `max_sends` it ends in `failed` (`rate_limited`). A step retried before its
state was saved issues the same send again: the store keeps the hash its first job is delivering and nothing is
queued twice. Every `failed` exit drops the pending code.

**Senders.** `Fapost\Foundation\Auth\Contracts\OneTimeCodeSenderInterface`, one per channel, through
`OneTimeCodeSenderRegistryInterface` (Core's `OneTimeCodeSenderRegistry`, frozen after boot). Core's default email
sender (`EmailOneTimeCodeSender`) mails a fixed text (`lang/*/auth_code.php`, `mail.auth.code`) in the contact's
language, signed with the assistant's name, through the platform mailer; a package's registered sender replaces it.
There is no SMS sender in Core.

**Publish.** `validate()` (`NodeConfigValidatorInterface`): errors for a missing prompt, a missing share-button label (phone), a limit out of bounds, an
email code without `destination`, a code method without a sender for its channel; a warning for a `destination`
built from `flow.*`. `ChannelCapabilityChecker` warns when a channel cannot share a contact (phone) or show callback
buttons (a code method with `resend_label`).

**Not in the trace.** `NodeTraceRecorder` leaves out the incoming text of an `auth_request` node. The contact's reply
stays in the conversation history (decision D8).

Tests: `tests/Feature/Domains/Flow/AuthRequestV2NodeHandlerTest.php`, `AuthRequestEngineTest.php`, `SendOneTimeCodeJobTest.php`,
`ChannelCapabilityCheckerTest`, `NodeTraceRecordingTest`, `SystemStateNamespacePolicyTest`.

## Not built

- An SMS sender: SMS codes work once a connector registers one.
- A lifetime for `is_authenticated` (`authenticated_at`, a TTL on the gate): the flag stays until something clears it.
- `input` of type `contact` still accepts a forwarded card; only `auth_request` checks the card's owner.
- Gating by feature activation; the node is always available.
