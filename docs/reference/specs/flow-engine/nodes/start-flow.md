# Node: `start_flow`

Starts another flow as a new conversation of a contact the node names by an expression, and goes on at once. It does
not wait for the started conversation (`subflow` does).

**Type:** `start_flow` · **Version:** 1 · **Category:** `Logic` · **Context:** `any`
**Class:** `app/Domains/Flow/Handlers/StartFlowNodeHandler.php` · **Job:** `app/Jobs/Flow/StartFlowForContactJob.php`
**Decision:** `adr-20261123-start-flow-for-contact`

## Config

```json
{
  "flow_id": "<id of a flow of the same assistant>",
  "addressee": {"kind": "contact", "contact": "{{flow.customer_id}}"},
  "input_mapping": {"order_id": "{{flow.order_id}}"},
  "busy_window_minutes": 30
}
```

| Field | Description |
|---|---|
| `flow_id` | Required, literal. A flow (not a template) of the same assistant as this flow; picked with `FlowPickerField`. |
| `addressee` | Required. The `send_message` addressee shape (`Support\AddresseeResolver`); only `kind: contact`. Never falls back to the run's contact. |
| `input_mapping` | Optional. The started conversation's `flow.*` variables, evaluated in the asking run (`SubflowInputEvaluator`); a value that is one placeholder keeps its type. |
| `busy_window_minutes` | Optional, 0–1440, default 30. A live conversation with the target's bot that moved within it makes the contact busy; 0 = never busy by time. |

## Exits

| Handle | When | Metadata |
|---|---|---|
| `started` | The start is queued, or this step's start already ran (a session with its start key exists) | `flow_id`, `contact_id`, `start_key`, `session_id` on a repeat |
| `busy` | `ContactFlowStartCollision` says busy: `operator` (the delivery thread is `staff` or `queued`), `conversation_moving` (a live session moved within the window), `own_conversation` (the asking session or a parent of it is the contact's live session), `conversation_scheduled` (a live session is `paused` in a `delay`) | `reason` |
| `no_channel` | The contact has no `channel_contacts` row on an active channel of the target's assistant (`ContactChannelProbe`, the pick `FlowMessageSender` makes) | — |
| `error` | `addressee_unresolved`, `contact_not_found`, `target_inactive`, `chain_depth_exceeded` | `error_type`, `error` |

## Execution

1. Render the addressee → a UUID, else `error/addressee_unresolved`. The contact must exist in the tenant.
2. `start_key = <idempotencyKey>:<nodeId>` (sha256 when longer than 191). A session with that key → `started`.
3. The target needs an active runnable definition (`findLatestActiveByFlowId`); its assistant is the target draft's
   `assistant_id`, not the run's — a run without an assistant can start a conversation.
4. The delivery thread (`ContactChannelProbe`) → else `no_channel`.
5. Depth = the running session's `event_chain_depth` + 1; over `MAX_CHAIN_DEPTH` (5) → `error/chain_depth_exceeded` and
   the warning `flow.event.chain_depth_exceeded`.
6. Collision pre-check → `busy`.
7. Queue `StartFlowForContactJob` after commit through `ContactFlowStartDispatcherInterface`; `started`.

The job (`scheduled.triggers`, `TenantAccessGatedJob`, dropped for a stopped tenant) re-reads the target, the contact,
the channel and the collision under `FlowExecutionGuard::run(tenant, contact, target assistant)`. A used start key
starts nothing; busy, no channel or an inactive target are logged (`flow.start_for_contact.{busy,no_channel,
target_inactive,contact_not_found}`) and never thrown. Stale live sessions are ended by `FlowSessionTerminator`
(`terminated_by_user`, pending operator request cancelled, waiting subflow parents ended, as `/reset` does), then
`FlowEngine::start(…, ['flow' => input], FlowRunOrigin::conversation(chainDepth, startKey))`. A unique violation on
`start_key` from a racing duplicate is a quiet exit. The target's access policy is not applied.

## Validation

`StartFlowNodeHandler::validate()`: `addressee_required`, `addressee_kind_unknown`, `start_flow_invalid_busy_window`,
and the `SubflowMappingValidator` input codes. `ValidateFlowService`: `start_flow_template_in_flow_id`,
`start_flow_target_unknown` (no draft, a template, or another assistant's flow). `ContactContextChecker` checks the
config like any node's: `contact.*` in the addressee under a contactless trigger is `node_needs_contact`.
