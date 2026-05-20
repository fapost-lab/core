<script setup lang="ts">
import {computed} from 'vue'
import {useKnownGroups} from '@builder/composables/useKnownGroups'
import AccordionSection from '../AccordionSection.vue'
import VariableStorageEditor from '@builder/components/editor/variables/VariableStorageEditor.vue'
import type {Variable} from '@builder/dto/types'

const props = defineProps({
    node:   { type: Object as () => Record<string, unknown>, required: true },
    schema: { type: Object as () => Record<string, unknown>, required: true },
})

const emit = defineEmits(['update:config'])

const config = computed((): Record<string, unknown> => (props.node.config as Record<string, unknown>) ?? {})

const url             = computed(() => String(config.value.url ?? ''))
const timeout         = computed(() => (config.value.timeout as number | string | undefined) ?? 10)
const saveToVariable  = computed<Variable | null>(() => {
    const raw = config.value.save_to_variable as Record<string, unknown> | null | undefined
    return raw && typeof raw === 'object' ? (raw as unknown as Variable) : null
})

const knownGroups = useKnownGroups()

function update(patch: Record<string, unknown>) {
    emit('update:config', patch)
}

function onVariableUpdate(next: Variable) {
    update({ save_to_variable: next, save_response_to: undefined })
}
</script>

<template>
    <div class="accordion">
        <AccordionSection title="Connection" default-open>
            <div class="config-field">
                <div class="field-label">URL</div>
                <input
                    class="field-input"
                    type="text"
                    :value="url"
                    placeholder="https://example.com/webhook"
                    @input="update({ url: ($event.target as HTMLInputElement).value })"
                >
            </div>
            <div class="config-field">
                <div class="field-label">Timeout (seconds)</div>
                <input
                    class="field-input"
                    type="number"
                    min="1"
                    max="300"
                    :value="timeout"
                    @input="update({ timeout: Number(($event.target as HTMLInputElement).value) })"
                >
            </div>
        </AccordionSection>

        <AccordionSection title="Response">
            <div class="config-field">
                <VariableStorageEditor
                    :model-value="saveToVariable"
                    :known-groups="knownGroups"
                    :owner-node-id="String(props.node.id)"
                    :show-type="false"
                    show-storage
                    show-group
                    @update:model-value="onVariableUpdate"
                />
                <p class="field-hint">
                    Full response body is written here. Cross-namespace name uniqueness applies — once a name is
                    used anywhere in this flow, the storage is locked to its first registration.
                </p>
            </div>
        </AccordionSection>
    </div>
</template>

<style scoped>
.field-hint {
    font-weight: 400;
    font-size: 10px;
    color: var(--text-3);
    margin-left: 4px;
}
</style>
