<script setup lang="ts">
import {ref, watch} from 'vue'
import AccordionSection from '../AccordionSection.vue'
import VariableStorageEditor from '@builder/components/editor/variables/VariableStorageEditor.vue'
import {useKnownGroups} from '@builder/composables/useKnownGroups'
import {compileVariable, decodeInputVariable} from '@builder/utils/variableCompiler'
import type {Variable} from '@builder/dto/types'

const props = defineProps({
    node:   { type: Object, required: true },
    schema: { type: Object, required: true },
})

const emit = defineEmits(['update:config'])

// Aligned with Input node taxonomy from spec 02 — both `expected_type`
// (validation) and `variable.type` (UI metadata) are written from this
// single picker.
const INPUT_TYPES = [
    { value: 'text',     label: 'Text' },
    { value: 'number',   label: 'Number' },
    { value: 'email',    label: 'Email' },
    { value: 'phone',    label: 'Phone' },
    { value: 'contact',  label: 'Contact (button)' },
    { value: 'select',   label: 'Select (from buttons)' },
    { value: 'confirm',  label: 'Yes/No' },
    { value: 'file',     label: 'File' },
    { value: 'photo',    label: 'Photo' },
    { value: 'location', label: 'Location' },
    { value: 'date',     label: 'Date' },
]

const knownGroups = useKnownGroups()

const variable = ref<Variable>(decodeInputVariable(props.node.config as Record<string, unknown>))

// Re-decode when the selected node changes or upstream config mutates.
watch(
    () => props.node.id,
    () => {
        variable.value = decodeInputVariable(props.node.config as Record<string, unknown>)
    },
)

function update(key: string, value: unknown) {
    emit('update:config', { [key]: value })
}

function onVariableUpdate(next: Variable) {
    variable.value = next
    const compiled = compileVariable(next)
    // Compiler is the sole writer of save target — drop legacy `save_to`
    // so the backend validator does not reject coexistence of both shapes.
    emit('update:config', {
        variable:      compiled,
        expected_type: next.type,
        save_to:       undefined,
    })
}
</script>

<template>
    <div class="accordion">
        <AccordionSection title="Variable" default-open>
            <VariableStorageEditor
                :model-value="variable"
                :type-options="INPUT_TYPES"
                :known-groups="knownGroups"
                show-storage
                show-group
                @update:model-value="onVariableUpdate"
            />
        </AccordionSection>

        <AccordionSection title="Validation">
            <div class="config-field">
                <div class="field-label">Retry limit</div>
                <input
                    class="field-input"
                    type="number"
                    style="width:100px"
                    :value="props.node.config?.retry_limit ?? 3"
                    @input="update('retry_limit', ($event.target as HTMLInputElement).value === '' ? null : Number(($event.target as HTMLInputElement).value))"
                >
            </div>
            <div class="config-field">
                <div class="field-label">On invalid message</div>
                <input
                    class="field-input"
                    :value="props.node.config?.on_invalid_message ?? ''"
                    placeholder="Please enter a valid value"
                    @input="update('on_invalid_message', ($event.target as HTMLInputElement).value)"
                >
            </div>
            <div class="config-field">
                <div class="field-label">Timeout <small>e.g. 24h, 300s</small></div>
                <input
                    class="field-input"
                    :value="props.node.config?.timeout ?? ''"
                    placeholder="24h"
                    @input="update('timeout', ($event.target as HTMLInputElement).value)"
                >
            </div>
        </AccordionSection>

        <AccordionSection title="Meta">
            <div class="config-field">
                <div class="field-label">Node ID</div>
                <input
                    class="field-input"
                    style="font-family:'Victor Mono',monospace;font-size:11.5px"
                    :value="props.node.id"
                    readonly
                >
            </div>
        </AccordionSection>
    </div>
</template>
