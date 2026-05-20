<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import AccordionSection from '../AccordionSection.vue'
import VariableStorageEditor from '@builder/components/editor/variables/VariableStorageEditor.vue'
import {useKnownGroups} from '@builder/composables/useKnownGroups'
import {useBuilderStore} from '@builder/store/builderStore'
import {compileVariable, decodeInputVariable} from '@builder/utils/variableCompiler'
import type {Variable} from '@builder/dto/types'

const builderStore = useBuilderStore()

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

const baseLanguage = computed(() => builderStore.contentBaseLanguage)

function localizedFieldValue(raw: unknown): string {
    if (raw == null) return ''
    if (typeof raw === 'string') return raw
    if (typeof raw === 'object') {
        const map = raw as Record<string, unknown>
        return String(map[baseLanguage.value] ?? Object.values(map)[0] ?? '')
    }
    return String(raw)
}

function emitLocalizedField(key: string, value: string) {
    const existing = props.node.config?.[key]
    if (existing && typeof existing === 'object') {
        emit('update:config', {[key]: {...(existing as Record<string, unknown>), [baseLanguage.value]: value}})
        return
    }
    // Promote plain strings to a language-keyed object so subsequent
    // Content-tab translations don't clobber the base-language value.
    emit('update:config', {[key]: {[baseLanguage.value]: value}})
}

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
    // SaveDraft persists whatever the UI shows, including half-filled
    // descriptors. Publish + the Validate button surface empty names
    // as validation errors — no client-side gating here.
    emit('update:config', {
        variable: compileVariable(next),
        expected_type: next.type,
        save_to:       undefined,
    })
}
</script>

<template>
    <div class="accordion">
        <AccordionSection default-open title="Prompt">
            <div class="config-field">
                <div class="field-label">Question</div>
                <textarea
                    :value="localizedFieldValue(props.node.config?.prompt)"
                    class="field-input"
                    placeholder="What the bot asks before waiting (e.g. «Send me your phone number»)"
                    rows="3"
                    @input="emitLocalizedField('prompt', ($event.target as HTMLTextAreaElement).value)"
                />
                <p class="field-help">
                    Sent to the user right before the bot starts waiting for input.
                    Leave empty to skip — useful when a preceding send_message already asked the question.
                </p>
            </div>
        </AccordionSection>

        <AccordionSection title="Variable" default-open>
            <VariableStorageEditor
                :model-value="variable"
                :type-options="INPUT_TYPES"
                :known-groups="knownGroups"
                :owner-node-id="String(props.node.id)"
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
                    :value="localizedFieldValue(props.node.config?.on_invalid_message)"
                    placeholder="Please enter a valid value"
                    @input="emitLocalizedField('on_invalid_message', ($event.target as HTMLInputElement).value)"
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
