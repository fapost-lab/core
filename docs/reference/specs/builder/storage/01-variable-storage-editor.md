# 01 · VariableStorageEditor — shared component

**Layer:** builder (Vue)
**Used by:** Input, SendMessage `save_to`, Assign, Branch source picker (see 02, 03, 04, 06, 07).

## Purpose

One Vue component that encapsulates the four fields of a "user variable": Name, Type, Save to, Group.
It is reused wherever a node stores user data. The component owns UI and local state only;
compiling the value into the JSON snapshot is the parent node's job (see 02–04).

## Component API

```ts
interface Variable {
    name:    string                 // letters, digits, underscore
    type:    VariableType           // see below
    storage: 'contact' | 'session'  // default: 'contact'
    group:   string | null          // null = root; always null for 'session'
    isList?: boolean                // when true, `type` is the array's item type
}

defineProps<{
    modelValue:   Variable | null
    typeOptions?: Array<{ value: string; label: string }>  // narrow the list per context
    knownGroups?: string[]                                  // groups offered by the dropdown
    showGroup?:   boolean                                   // default true
    showStorage?: boolean                                   // default true
}>()
defineEmits<{ (e: 'update:modelValue', value: Variable): void }>()
```

`VariableType` (`resources/js/builder/dto/types.ts`, mirrors the PHP enum
`App\Domains\Flow\State\Variables\VariableType`): `text`, `number`, `boolean`, `phone`, `email`,
`contact`, `select`, `confirm`, `file`, `photo`, `location`, `date`, `json`, `array`.

The default `typeOptions` list offers every type except `boolean` and `array` (an array is
expressed with the `isList` checkbox). Nodes that need a narrower list pass `typeOptions`.

## Behaviour

- **Name** is a combobox (`NameSelect.vue`): typing filters known variable names; picking a suggestion
  reuses that variable, an unmatched name simply becomes a new variable.
- **Cross-namespace uniqueness.** A name belongs to exactly one storage. When the chosen name is already
  registered elsewhere in the flow, the editor snaps to that registration's storage and group and locks
  the storage radio.
- **Group** is visible only when `storage === 'contact'`; switching to `session` clears it (`group: null`
  in the emitted value). The dropdown offers existing groups and an inline "Create new group" field
  (no modal).
- Validation is inline and does not block emitting; the parent decides what to do with an invalid value.

| Field | Rule | Message |
|------|------|---------|
| Name | not empty | `Name is required` |
| Name | no dot | `Group depth is limited to 1 level` |
| Name | letters, digits, underscore | `Use letters, digits, underscore only` |
| Name | not reserved (`id`, `channel_id`, `tenant_id`, `external_id`, `meta`, `language`, `is_blocked`, `created_at`, `updated_at`) | `Name is reserved` |
| Group | not `meta` | `Group "meta" is reserved` |

## Files

- `resources/js/builder/components/editor/variables/VariableStorageEditor.vue` — main component
- `.../variables/NameSelect.vue` — name combobox
- `.../variables/StorageRadio.vue` — Contact profile / Temporary radio
- `.../variables/GroupSelect.vue` — group dropdown with inline create
- `resources/js/builder/composables/useKnownGroups.ts` — collects group names from the flow's nodes
- `resources/js/builder/composables/useFlowVariables.ts` — known variables used for name suggestions

## Out of scope

- Drag-and-drop reordering of groups.
- Tooltips with examples.
- Bulk rename of variables.

## Related

- [02-input-node-migration](02-input-node-migration.md), [05-backend-contract](05-backend-contract.md)
