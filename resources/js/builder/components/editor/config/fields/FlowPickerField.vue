<script setup lang="ts">
/**
 * Searchable flow picker. Renders a dropdown over the assistant's flows
 * (`builderStore.availableFlows`) and persists the selected `flow.id`
 * (UUID, language-agnostic) — never the name.
 *
 * The current flow is excluded by default (`exclude_current`), since a
 * flow referencing itself (e.g. `subflow`) is almost always a mistake.
 * Data comes from the runtime store, not the schema, mirroring how
 * StatePicker / VariablePicker read `useBuilderStore()`.
 */
import {computed} from 'vue'
import SearchableSelect from '../SearchableSelect.vue'
import {useBuilderStore} from '@builder/store/builderStore'

interface FlowPickerSchema {
    placeholder?: string
    /** Hide the current flow from the list. Default true. */
    exclude_current?: boolean
}

const props = defineProps({
    value:  { type: [String, null] as unknown as () => string | null, default: '' },
    schema: { type: Object as () => FlowPickerSchema, default: () => ({}) },
})

const emit = defineEmits(['update:value'])

const builderStore = useBuilderStore()

const excludeCurrent = computed(() => props.schema?.exclude_current !== false)

const flows = computed(() =>
    builderStore.availableFlows.filter(
        (f) => !excludeCurrent.value || f.id !== builderStore.flowId,
    ),
)

// SearchableSelect operates on plain string labels, so we keep an id→name
// map to display the flow name while the stored config value stays the id.
const names    = computed(() => flows.value.map((f) => f.name))
const idToName  = computed(() => new Map(flows.value.map((f) => [f.id, f.name] as const)))

const selectedName = computed(() => idToName.value.get(props.value ?? '') ?? '')

function onSelect(name: string) {
    const flow = flows.value.find((f) => f.name === name)
    emit('update:value', flow ? flow.id : '')
}
</script>

<template>
    <SearchableSelect
        :model-value="selectedName"
        :options="names"
        :placeholder="schema?.placeholder ?? '— select a flow —'"
        empty-text="No other flows"
        @update:model-value="onSelect"
    />
</template>
