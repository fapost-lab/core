<script setup lang="ts">
import {computed} from 'vue'
import AccordionSection from './AccordionSection.vue'
import TextField from './fields/TextField.vue'
import TextareaField from './fields/TextareaField.vue'
import SelectField from './fields/SelectField.vue'
import ToggleField from './fields/ToggleField.vue'
import ArrayField from './fields/ArrayField.vue'
import JsonField from './fields/JsonField.vue'
import StatePickerField from './fields/StatePickerField.vue'

const props = defineProps({
    node:   { type: Object, required: true },
    schema: { type: Object, required: true },
})

const emit = defineEmits(['update:config'])

// Schema-driven config renderer. Picks the right field component per
// `schema[key].type`, falling through to TextField when the type is
// unknown. Bespoke node configs (send_message, input, condition, trigger)
// are handled outside this component via ConfigPanel's overrides map.
const FIELD_COMPONENTS: Record<string, object> = {
    string:         TextField,
    text:           TextareaField,
    number:         TextField,
    boolean:        ToggleField,
    enum:           SelectField,
    array:          ArrayField,
    json:           JsonField,
    'state-picker': StatePickerField,
}

interface FieldEntry {
    key: string
    schema: Record<string, unknown>
    required: boolean
    component: object
}

// Top-level `required: [...]` enumerates required field keys instead of
// describing a field itself. Filter it out and merge into per-field flags.
const fields = computed<FieldEntry[]>(() => {
    const raw = (props.schema ?? {}) as Record<string, unknown>
    const requiredList = Array.isArray(raw.required) ? (raw.required as string[]) : []

    const entries: FieldEntry[] = []

    for (const [key, value] of Object.entries(raw)) {
        if (key === 'required') continue
        if (!isFieldSchema(value)) continue

        const fieldSchema = value as Record<string, unknown>
        const type = String(fieldSchema.type ?? 'string')

        entries.push({
            key,
            schema: fieldSchema,
            required: Boolean(fieldSchema.required) || requiredList.includes(key),
            component: FIELD_COMPONENTS[type] ?? TextField,
        })
    }

    return entries
})

function isFieldSchema(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && 'type' in (value as Record<string, unknown>)
}

function valueFor(key: string, fieldSchema: Record<string, unknown>): unknown {
    const cfg = (props.node?.config ?? {}) as Record<string, unknown>
    const stored = cfg[key]
    if (stored !== undefined) {
        return stored
    }
    return fieldSchema.default
}

function update(key: string, value: unknown) {
    emit('update:config', { [key]: value })
}
</script>

<template>
    <div class="accordion">
        <AccordionSection title="Configuration" default-open>
            <div
                v-for="field in fields"
                :key="field.key"
                class="config-field"
            >
                <div class="field-label">
                    {{ field.schema.label ?? field.key }}
                    <span v-if="field.required" class="schema-required">*</span>
                </div>
                <component
                    :is="field.component"
                    :value="valueFor(field.key, field.schema)"
                    :schema="field.schema"
                    @update:value="update(field.key, $event)"
                />
                <p v-if="field.schema.help" class="field-help">
                    {{ field.schema.help }}
                </p>
            </div>
        </AccordionSection>

        <AccordionSection title="Meta">
            <div class="config-field">
                <div class="field-label">Node ID</div>
                <input
                    class="field-input"
                    style="font-family:'Victor Mono',monospace;font-size:11.5px"
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
.field-help {
    font-size: 11px;
    color: var(--text-3);
    margin-top: 4px;
    line-height: 1.35;
}
</style>
