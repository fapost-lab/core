# Node: `set_tag`

Adds, removes or toggles a tag on the current contact.

**Type:** `set_tag` · **Version:** 1 · **Category:** `Data`
**Class:** `app/Domains/Flow/Handlers/SetTagNodeHandler.php`

Tags are one of three segmentation concepts (Groups / **Tags** / Segments) and are used by Broadcasting, and by the
`notify` node in contacts mode, to select recipients.

## Config

```json
{
  "action": "add",
  "tags": ["paid", "{{flow.plan}}"]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `action` | enum | yes | `TagAction`: `add` (default), `remove`, `toggle` |
| `tags` | list of strings | no | Language-agnostic labels, not translated. Each is rendered as a template; blank results are dropped and duplicates within the node are collapsed |

## UI

The node has a Core builder override, `SetTagConfig.vue`
(`resources/js/builder/components/editor/config/overrides/SetTagConfig.vue`), registered in the `OVERRIDES` map of
`ConfigPanel.vue`. `configSchema()` (built with the fluent `Schema` API) remains the contract.

## Output handles

- `default`: always. Tagging does not branch.

## Behavior and idempotency

The handler writes through `ContactTagRepositoryInterface` (bound to `ContactTagRepository` in
`ContactServiceProvider`), not through Eloquent directly, into the tenant table `contact_tags`
(`contact_id`, `tag`, `tagged_by`, `tagged_at`; unique `(contact_id, tag)`). `tagged_by` is the current
`flow_session_id`.

1. If the marker `system.set_tag.{nodeId}` is already `true`, return `Executed` with metadata `replayed = true` and
   change nothing. The marker matters because `toggle` flips on every run, so a re-execution under the session lock
   must skip the mutations entirely.
2. Resolve the tags: render each, drop blank, collapse duplicates.
3. Apply the action per tag: `add` (an upsert on `(contact_id, tag)`), `remove` (a delete; harmless to repeat),
   `toggle` (flips by the current presence).
4. Return `Executed` with `stateChanges = {system.set_tag.{nodeId}: true}` and metadata `action` and `tags`. `set_tag`
   is a whitelisted writer of `system.*` in `SystemStateNamespacePolicy`.

An unknown `action` falls back to `add`.

## Tests

`tests/Feature/Domains/Flow/SetTagNodeHandlerTest.php`: the three actions, idempotency, template tags, blank tags.

## Related

- [notify.md](notify.md) - notifying contacts by tag
