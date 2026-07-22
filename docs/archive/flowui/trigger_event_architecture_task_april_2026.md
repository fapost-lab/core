# Trigger & Event Architecture Task

## Trigger model (current decision)

### Ownership

Trigger belongs strictly to one flow.

```text
1 flow ↔ 0..1 trigger
```

Trigger is not shared between flows.

Trigger is not selected from existing triggers.

Trigger lifecycle is subordinate to flow lifecycle.

---

## Persistence model

`flow_triggers` must have explicit relation to logical flow:

```text
flow_id
```

Trigger must reference logical flow, not snapshot version.

Reason:

Trigger belongs to business flow identity, not to particular flow_definition snapshot.

---

## Builder integration

Trigger remains runtime-external entity but is rendered in builder as pseudo-node.

### UI behavior

- Trigger shown as first fixed block
- Not draggable
- Not part of graph execution nodes
- Click opens right-side config panel

---

## Trigger config in builder

Example for message trigger:

```json
{
  "type": "message",
  "config": {
    "keywords": [],
    "phrases": []
  },
  "priority": 100,
  "is_active": true
}
```

---

## Save contract

Builder save must persist two entities in one transaction:

```text
save flow_draft
upsert flow_trigger
```

Inside transaction only.

Trigger deletion must be explicit.

Example:

```text
delete related trigger
```

Empty trigger payload is not interpreted as delete.

Any trigger without required properties must fail validation.

---

## Trigger loading

Builder load:

```text
load flow
load related trigger
```

If no trigger exists:

builder renders empty inactive trigger block.

---

## Trigger types

Current supported trigger types:

- message
- schedule
- webhook
- api
- event

---

## Message trigger resolution strategy

Recommended runtime pipeline:

```text
1 exact match
2 contains match
3 search engine fallback
```

### 1. Normalize input

Before matching:

- trim spaces
- lowercase
- remove punctuation
- collapse multiple spaces

Example:

```text
" Отпуск!!! " → "отпуск"
```

---

### 2. Exact match

First strict lookup:

```text
normalized_input == trigger keyword
```

If exact match found:

- trigger resolved
- flow starts immediately
- search stops

---

### 3. Contains match

If exact not found:

```text
input contains trigger keyword
```

Example:

```text
input: "хочу оформить отпуск"
keyword: "отпуск"
```

If multiple matches found:

- sort by priority
- choose first trigger

---

### 4. Search engine fallback

If exact and contains fail:

Use search engine (Scout / Meilisearch / TNTSearch).

Search engine returns candidates.

Candidate selection:

```text
search_score DESC
priority DESC
```

Use first candidate only if score passes threshold.

---

### 5. No trigger found

If no candidate found:

```text
default assistant response
```

---

Search engine must not be source of truth.

Source of truth remains:

```text
flow_triggers table
```

Index is projection only.

---

## Trigger priority

Avoid ranking hacks like repeated tags.

Instead explicit field:

```text
priority
```

Resolver final score:

```text
search_score + priority_modifier
```

---

# Event trigger model (future-safe decision)

## Event trigger ownership

Trigger still belongs to one flow.

Example:

```json
{
  "type": "event",
  "config": {
    "event_name": "employee_registered"
  }
}
```

---

## Event scope

Event is tenant-wide.

Not assistant-local.

Reason:

Event is internal domain signal usable across assistants.

---

## Runtime event fan-out

Example:

Assistant A emits:

```text
employee_registered
```

System resolves all triggers inside tenant:

```text
type = event
event_name = employee_registered
```

And starts all related flows.

Including flows of different assistants.

---

## Event registry

Event registry lives in database and is tenant-wide.

Suggested shape:

```text
tenant_id + event_name
```

Unique key:

```text
(tenant_id, event_name)
```

At first stage `event_name` is a simple tenant-wide unique key without namespace.

Recommended format:

```text
employee_registered
shipment_ready
user_verified
```

Validation:

- snake_case only
- no spaces
- no camelCase
- no dots
- no hyphens

---

## Producer metadata

Assistant identity must not be inside canonical event name.

Wrong:

```text
assistant_42_employee_registered
```

Correct:

```json
{
  "event_name": "employee_registered",
  "emitter_assistant_id": "..."
}
```

---

## Why producer metadata separately

This keeps event name domain-clean.

At same time runtime knows who emitted event.

---

## Future trigger filter (optional later)

Possible future extension:

```json
{
  "event_name": "employee_registered",
  "only_from_assistant": "assistant_x"
}
```

Allows subscription only to selected producer.

---

## Builder UX for event trigger

When trigger type = event:

Right panel:

```text
Event:
[ employee_registered ▼ ]
```

At first stage recommended:

selection from registry with ability to create tenant-local event name if needed.

---

## Event emission

Separate flow node:

```text
emit_event
```

Example config:

```json
{
  "event_name": "employee_registered"
}
```

---

## Final architectural separation

```text
Trigger = external entry
Event = internal orchestration signal
```

Trigger starts one flow.

Event can start many flows.
