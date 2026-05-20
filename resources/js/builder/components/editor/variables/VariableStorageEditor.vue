<script lang="ts">
interface TypeOption {
    value: string
    label: string
}

// Default option list — broad set; nodes that need a narrower one pass
// `typeOptions` explicitly. Defined in module scope so `withDefaults`
// can reference it (script-setup hoisting restriction).
const DEFAULT_TYPE_OPTIONS: TypeOption[] = [
    { value: 'text',     label: 'Text' },
    { value: 'number',   label: 'Number' },
    { value: 'phone',    label: 'Phone' },
    { value: 'email',    label: 'Email' },
    { value: 'contact',  label: 'Contact' },
    { value: 'select',   label: 'Select' },
    { value: 'confirm',  label: 'Confirm' },
    { value: 'file',     label: 'File' },
    { value: 'photo',    label: 'Photo' },
    { value: 'location', label: 'Location' },
    { value: 'date',     label: 'Date' },
]

// Reserved attribute names that collide with canonical contact columns or
// JSON envelope keys — see Contact Domain in CLAUDE.md.
const RESERVED_NAMES: ReadonlySet<string> = new Set([
    'id', 'channel_id', 'tenant_id', 'external_id',
    'meta', 'language', 'is_blocked',
    'created_at', 'updated_at',
])

const RESERVED_GROUPS: ReadonlySet<string> = new Set(['meta'])

const NAME_PATTERN = /^[A-Za-z0-9_]+$/

export type { TypeOption }
</script>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import StorageRadio from './StorageRadio.vue'
import GroupSelect from './GroupSelect.vue'
import NameSelect from './NameSelect.vue'
import { useFlowVariables } from '@builder/composables/useFlowVariables'
import type { Variable, VariableStorage, VariableType } from '@builder/dto/types'

const props = withDefaults(defineProps<{
    modelValue:   Variable | null
    typeOptions?: TypeOption[]
    knownGroups?: string[]
    showGroup?:   boolean
    showStorage?: boolean
}>(), {
    typeOptions: () => DEFAULT_TYPE_OPTIONS,
    knownGroups: () => [],
    showGroup:   true,
    showStorage: true,
})

const emit = defineEmits<{
    (e: 'update:modelValue', value: Variable): void
}>()

function defaultVariable(): Variable {
    return {
        name:    '',
        type:    (props.typeOptions[0]?.value ?? 'text') as VariableType,
        storage: 'contact',
        group:   null,
    }
}

const state = ref<Variable>(props.modelValue ?? defaultVariable())

// Local mirror — keep external updates in sync with the editor's local
// state without dropping in-flight edits.
watch(() => props.modelValue, (next) => {
    if (next && next !== state.value) {
        state.value = { ...next }
    }
}, { deep: true })

const localKnownGroups = ref<string[]>([...props.knownGroups])
watch(() => props.knownGroups, (next) => {
    localKnownGroups.value = [...next]
})

const { userVars } = useFlowVariables()

// Known variable names for the current storage type — deduped and sorted for NameSelect.
const knownNames = computed<string[]>(() => {
    const isSession = state.value.storage === 'session'
    const prefix    = isSession ? 'flow.' : 'contact.'
    const names = userVars.value
        .filter((v) => v.path.startsWith(prefix))
        .map((v) => v.path.slice(prefix.length).split('.').at(-1) ?? '')
        .filter((n) => n !== '')
    return [...new Set(names)].sort()
})

// Tracks the last name explicitly chosen from the NameSelect dropdown.
// When the user picks from the list they know it exists — suppress the warning.
const pickedName = ref<string | null>(null)

const isExistingName = computed<boolean>(() => {
    const name = state.value.name.trim()
    if (name === '' || pickedName.value === name) return false
    return knownNames.value.includes(name)
})

const nameError = computed<string | null>(() => {
    const name = state.value.name
    if (name === '') {
        return 'Name is required'
    }
    if (name.includes('.')) {
        return 'Group depth is limited to 1 level'
    }
    if (!NAME_PATTERN.test(name)) {
        return 'Use letters, digits, underscore only'
    }
    if (RESERVED_NAMES.has(name)) {
        return 'Name is reserved'
    }
    return null
})

const groupError = computed<string | null>(() => {
    const group = state.value.group
    if (group !== null && RESERVED_GROUPS.has(group)) {
        return 'Group "meta" is reserved'
    }
    return null
})

const groupVisible = computed<boolean>(
    () => props.showGroup && state.value.storage === 'contact',
)

function emitUpdate() {
    const snapshot: Variable = {
        name:    state.value.name,
        type:    state.value.type,
        storage: state.value.storage,
        group:   state.value.storage === 'contact' ? state.value.group : null,
    }
    emit('update:modelValue', snapshot)
}

function onNamePick(value: string) {
    pickedName.value = value
}

function onNameSelect(value: string) {
    // Reset pick tracking when the user manually types a different name
    if (pickedName.value !== null && pickedName.value !== value) {
        pickedName.value = null
    }
    state.value.name = value
    emitUpdate()
}

function onTypeChange(event: Event) {
    state.value.type = (event.target as HTMLSelectElement).value as VariableType
    emitUpdate()
}

function onStorageChange(value: VariableStorage) {
    state.value.storage = value
    if (value === 'session') {
        state.value.group = null
    }
    emitUpdate()
}

function onGroupChange(value: string | null) {
    state.value.group = value
    emitUpdate()
}

function onGroupCreate(name: string) {
    if (!localKnownGroups.value.includes(name)) {
        localKnownGroups.value = [...localKnownGroups.value, name].sort()
    }
}
</script>

<template>
    <div class="variable-storage-editor">
        <div class="vse-section-title">Save as:</div>

        <div class="vse-card">
            <div class="vse-row">
                <label class="vse-label">Name:</label>
                <div class="vse-control">
                    <NameSelect
                        :model-value="state.name"
                        :known-names="knownNames"
                        :class="{ 'vse-name-error': nameError }"
                        placeholder="my_variable"
                        @pick="onNamePick"
                        @update:model-value="onNameSelect"
                    />
                    <div v-if="nameError" class="vse-error">{{ nameError }}</div>
                    <div v-else-if="isExistingName" class="vse-hint vse-hint--warn">
                        Already used in this flow — will overwrite.
                    </div>
                    <div v-else class="vse-hint">Latin letters, digits, underscore — snake_case</div>
                </div>
            </div>

            <div class="vse-row">
                <label class="vse-label">Type:</label>
                <div class="vse-control">
                    <select
                        class="vse-input"
                        :value="state.type"
                        @change="onTypeChange"
                    >
                        <option
                            v-for="opt in props.typeOptions"
                            :key="opt.value"
                            :value="opt.value"
                        >
                            {{ opt.label }}
                        </option>
                    </select>
                </div>
            </div>

            <div v-if="props.showStorage" class="vse-row vse-row-inline">
                <label class="vse-label">Save to:</label>
                <div class="vse-control vse-control-inline">
                    <StorageRadio
                        :model-value="state.storage"
                        @update:model-value="onStorageChange"
                    />
                </div>
            </div>

            <div v-if="groupVisible" class="vse-row">
                <label class="vse-label">Group:</label>
                <div class="vse-control">
                    <GroupSelect
                        :model-value="state.group"
                        :known-groups="localKnownGroups"
                        @update:model-value="onGroupChange"
                        @create="onGroupCreate"
                    />
                    <div v-if="groupError" class="vse-error">{{ groupError }}</div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.variable-storage-editor {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.vse-section-title {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-2);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.vse-card {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface-2);
}
.vse-row {
    display: flex;
    align-items: flex-start;
    gap: 8px;
}
.vse-row-inline {
    align-items: center;
}
.vse-control-inline {
    flex-direction: row;
    align-items: center;
}
.vse-label {
    flex: 0 0 70px;
    padding-top: 7px;
    font-size: 12px;
    color: var(--text-2);
}
.vse-control {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.vse-input {
    width: 100%;
    padding: 6px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
    color: var(--text);
    font-size: 13px;
    font-family: inherit;
}
.vse-input:focus {
    outline: none;
    border-color: var(--primary);
}
.vse-input--error {
    border-color: var(--rose);
}
.vse-name-error :deep(.ns-trigger),
.vse-name-error :deep(.ns-input) {
    border-color: var(--rose);
}
.vse-error {
    font-size: 11px;
    color: var(--rose);
    line-height: 1.3;
}
.vse-hint {
    font-size: 11px;
    color: var(--text-3);
    line-height: 1.3;
}
.vse-hint--warn {
    color: var(--amber, #d97706);
}
</style>
