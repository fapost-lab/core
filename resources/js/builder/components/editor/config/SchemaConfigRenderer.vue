<script setup lang="ts">
import {computed} from 'vue'
import AccordionSection from './AccordionSection.vue'
import TextField from './fields/TextField.vue'
import TextareaField from './fields/TextareaField.vue'
import SelectField from './fields/SelectField.vue'
import ToggleField from './fields/ToggleField.vue'
import ArrayField from './fields/ArrayField.vue'

const props = defineProps({
    node: { type: Object, required: true },
    schema: { type: Object, required: true },
})

const emit = defineEmits(['update:config'])

const FIELD_COMPONENTS: Record<string, object> = {
    string: TextField,
    text: TextareaField,
    number: TextField,
    boolean: ToggleField,
    enum: SelectField,
    array: ArrayField,
}

const schemaEntries = computed(() => Object.entries(props.schema ?? {}))

function update(key: string, value: unknown) {
    emit('update:config', { [key]: value })
}
</script>

<template>
    <div class="accordion">
        <AccordionSection title="Configuration" default-open>
            <div
                v-for="[key, fieldSchema] in schemaEntries"
                :key="key"
                class="config-field"
            >
                <div class="field-label">
                    {{ fieldSchema.label ?? key }}
                    <span v-if="fieldSchema.required" class="schema-required">*</span>
                </div>
                <component
                    :is="FIELD_COMPONENTS[fieldSchema.type] ?? TextField"
                    :value="node.config?.[key]"
                    :schema="fieldSchema"
                    @update:value="update(key, $event)"
                />
            </div>
        </AccordionSection>

        <AccordionSection title="Meta">
            <div class="config-field">
                <div class="field-label">Node ID</div>
                <input
                    class="field-input"
                    style="font-family:'DM Mono',monospace;font-size:11.5px"
                    :value="node.id"
                    readonly
                >
            </div>
        </AccordionSection>
    </div>
</template>

<style scoped>
.schema-required {
    color: #e53e3e;
    margin-left: 2px;
}
</style>
