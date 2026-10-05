# Node: `auth_request`

A Core flag-setter that marks the contact authenticated inside a flow.

**Type:** `auth_request` · **Version:** 1 · **Category:** `Contact`
**Class:** `app/Domains/Flow/Handlers/AuthRequestNodeHandler.php`

It is a Core node, not a Feature-level one: there is no `app/Features/` and no activation machinery for it.
The node is **not a brancher**: it has a single `default` output, sets the flag when the check passes and
continues either way.

## Challenge types (`AuthMethod`)

The node is challenge-typed through the `AuthMethod` enum (`app/Domains/Flow/Enums/AuthMethod.php`). Only
`basic` exists. A missing or unknown `method` falls back to `basic`.

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
   true; `PublishFlowService` copies it from the draft; the UI toggle is in the Filament flow form).
   `FlowAccessPolicy::canStart(definition, contact)` is `is_public || contact.is_authenticated`, and fails open
   for a `null` value. `FlowOrchestrator` applies it when it would start a **new** session: a private flow for an
   unauthenticated contact does not start and falls through to the normal fallback, as with "no flow available".
   Resuming an existing session, subflows and persistent-button re-entry are not gated.

## Tests

`tests/Feature/Domains/Flow/AuthRequestNodeHandlerTest.php`, `tests/Unit/Domains/Flow/FlowAccessPolicyTest.php`,
the `ContactWriterTest` canonical-column case, and `ScopedStateReaderTest`.

## Not built

- Further `AuthMethod` types: `phone` (contact share), `sms_code`, `email_code`. They would be new enum cases;
  the single `default` output does not change.
- Max-attempts handling and an `authenticated` / `failed` output pair (the earlier design); a failed check simply
  leaves the flag unset.
- Gating by feature activation; the node is always available.
