<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import AccordionSection from '../AccordionSection.vue'
import TextareaField from '../fields/TextareaField.vue'
import VariableStorageEditor from '@builder/components/editor/variables/VariableStorageEditor.vue'
import {useKnownGroups} from '@builder/composables/useKnownGroups'
import {type AssignOperation, compileVariable, decodeAssignOperations,} from '@builder/utils/variableCompiler'
import type {Variable, VariableType} from '@builder/dto/types'

// Assign-supported value types — plain scalars. An Assign node writes typed
// data into state; file/photo/location/contact are Input concerns. The "store
// as list" toggle wraps the chosen scalar into an append-only array.
const ASSIGN_TYPES = [
    { value: 'text',    label: 'Text' },
    { value: 'number',  label: 'Number' },
    { value: 'boolean', label: 'Boolean' },
    { value: 'date',    label: 'Date' },
]

const props = defineProps({
    node:   { type: Object as () => Record<string, unknown>, required: true },
    schema: { type: Object as () => Record<string, unknown>, required: true },
})

const emit = defineEmits(['update:config'])

const knownGroups = useKnownGroups()

function emptyOperation(): AssignOperation {
    return {
        variable: {
            name:    '',
            type:    'text' as VariableType,
            storage: 'session',
            group:   null,
        },
        value: '',
    }
}

function loadFromConfig(): AssignOperation[] {
    const decoded = decodeAssignOperations(props.node.config as Record<string, unknown>)
    return decoded.length > 0 ? decoded : [emptyOperation()]
}

const operations = ref<AssignOperation[]>(loadFromConfig())

// Re-decode when the selected node changes — same pattern as InputConfig.
watch(
    () => props.node.id,
    () => {
        operations.value = loadFromConfig()
    },
)

/**
 * Compute (storage, group, name) keys per operation and flag duplicates.
 * Duplicates are a hard validation error — the backend validator rejects them
 * but we surface inline before save so the user sees what to fix.
 */
const duplicateIndexes = computed<Set<number>>(() => {
    const seen = new Map<string, number>()
    const dupes = new Set<number>()
    operations.value.forEach((op, idx) => {
        const name = op.variable.name.trim()
        if (name === '') {
            return
        }
        const key = `${op.variable.storage}::${op.variable.group ?? ''}::${name}`
        if (seen.has(key)) {
            dupes.add(seen.get(key)!)
            dupes.add(idx)
        } else {
            seen.set(key, idx)
        }
    })
    return dupes
})

function persist() {
    // SaveDraft persists whatever the UI shows. We don't filter empty-
    // name rows here — Publish + the Validate button surface them as
    // proper errors so the author can see what to fix.
    const compiled = operations.value.map((op) => ({
        // compileVariable maps the "store as list" flag onto backend
        // { type: 'array', properties: { item_type } }.
        variable: compileVariable(op.variable),
        value:    op.value,
    }))

    // Drop legacy single-op keys — backend validator rejects coexistence.
    emit('update:config', {
        operations: compiled,
        target:     undefined,
        key:        undefined,
        value:      undefined,
    })
}

function onVariableUpdate(index: number, next: Variable) {
    const op = operations.value[index]
    if (!op) {
        return
    }
    op.variable = next
    persist()
}

function onValueUpdate(index: number, next: string) {
    const op = operations.value[index]
    if (!op) {
        return
    }
    op.value = next
    persist()
}

function addOperation() {
    operations.value.push(emptyOperation())
    persist()
}

function removeOperation(index: number) {
    if (operations.value.length <= 1) {
        return
    }
    operations.value.splice(index, 1)
    persist()
}
</script>

<template>
    <div class="accordion">
        <AccordionSection title="Operations" default-open>
            <div class="assign-ops">
                <div
                    v-for="(op, index) in operations"
                    :key="index"
                    class="assign-op"
                    :class="{ 'assign-op--dup': duplicateIndexes.has(index) }"
                >
                    <div class="assign-op-header">
                        <span class="assign-op-index">#{{ index + 1 }}</span>
                        <button
                            v-if="operations.length > 1"
                            type="button"
                            class="assign-op-remove"
                            title="Remove operation"
                            @click="removeOperation(index)"
                        >×</button>
                    </div>

                    <div class="config-field assign-op-value">
                        <div class="field-label">Value</div>
                        <TextareaField
                            :value="op.value"
                            :schema="{ placeholder: '{{flow.input}}' }"
                            @update:value="(next: string) => onValueUpdate(index, next)"
                        />
                    </div>

                    <VariableStorageEditor
                        :model-value="op.variable"
                        :type-options="ASSIGN_TYPES"
                        :known-groups="knownGroups"
                        :owner-node-id="String(props.node.id)"
                        show-storage
                        show-group
                        show-list
                        @update:model-value="(next: Variable) => onVariableUpdate(index, next)"
                    />

                    <div v-if="duplicateIndexes.has(index)" class="assign-op-error">
                        Duplicate variable target — same Save to + Group + Name as another operation.
                    </div>
                </div>

                <button type="button" class="assign-op-add" @click="addOperation">
                    + Add operation
                </button>
            </div>
        </AccordionSection>

        <AccordionSection title="Meta">
            <div class="config-field">
                <div class="field-label">Node ID</div>
                <input
                    class="field-input"
                    style="font-family:var(--font-mono);font-size:11.5px"
                    :value="props.node.id"
                    readonly
                >
            </div>
        </AccordionSection>
    </div>
</template>

<style scoped>
.assign-ops {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.assign-op {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
}
.assign-op--dup {
    border-color: var(--rose);
}
.assign-op-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.assign-op-index {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-3);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.assign-op-remove {
    width: 22px;
    height: 22px;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--surface-2);
    color: var(--text-2);
    font-size: 14px;
    line-height: 1;
    cursor: pointer;
}
.assign-op-remove:hover {
    background: var(--rose-bg, var(--surface-2));
    color: var(--rose);
    border-color: var(--rose);
}
.assign-op-value :deep(.textarea-with-picker) {
    width: 100%;
}
.assign-op-value :deep(textarea) {
    box-sizing: border-box;
    width: 100%;
    min-height: 64px;
    font-size: 13px;
}
.assign-op-add {
    align-self: flex-start;
    padding: 6px 12px;
    border: 1px dashed var(--border);
    border-radius: var(--radius);
    background: transparent;
    color: var(--text-2);
    font-size: 12px;
    cursor: pointer;
}
.assign-op-add:hover {
    border-color: var(--primary);
    color: var(--primary);
}
.assign-op-error {
    font-size: 11px;
    color: var(--rose);
    line-height: 1.3;
}
</style>
