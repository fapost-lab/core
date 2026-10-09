<script lang="ts">
export interface FieldEntry {
    key: string
    schema: Record<string, unknown>
    required: boolean
}
</script>

<script lang="ts" setup>
import {computed} from 'vue'
import TextField from './fields/TextField.vue'
import TextareaField from './fields/TextareaField.vue'
import SelectField from './fields/SelectField.vue'
import ToggleField from './fields/ToggleField.vue'
import ArrayField from './fields/ArrayField.vue'
import JsonField from './fields/JsonField.vue'
import StatePickerField from './fields/StatePickerField.vue'
import KeyValueField from './fields/KeyValueField.vue'
import ObjectField from './fields/ObjectField.vue'
import ObjectArrayField from './fields/ObjectArrayField.vue'
import FlowPickerField from './fields/FlowPickerField.vue'
import EnumCardsField from './fields/EnumCardsField.vue'
import DurationField from './fields/DurationField.vue'
import {resolveVisibility} from '@builder/composables/useFieldVisibility'

/**
 * Reusable field-loop. Reads field entries from a flat schema map and
 * renders them against a config object. Used by both the top-level
 * SchemaConfigRenderer (one instance per section) and the nested
 * ObjectField / ObjectArrayField components — the latter pass a
 * sub-config slice and want the same per-field UI without sections,
 * accordions or the Meta block.
 */
const props = defineProps({
    config: {type: Object as () => Record<string, unknown>, required: true},
    fields: {type: Array as () => FieldEntry[], required: true},
    /**
     * Root-level config used to resolve `visible_when` paths that may
     * reference fields outside this nested scope. Nested renderers
     * forward the same `rootConfig` they receive so cross-section
     * conditions keep working at any depth.
     */
    rootConfig: {type: Object as () => Record<string, unknown>, default: () => ({})},
})

const emit = defineEmits(['update:config'])

const FIELD_COMPONENTS: Record<string, object> = {
    string: TextField,
    text: TextareaField,
    number: TextField,
    boolean: ToggleField,
    enum: SelectField,
    array: ArrayField,
    json: JsonField,
    'state-picker': StatePickerField,
    'key-value': KeyValueField,
    object: ObjectField,
    'object-array': ObjectArrayField,
    'flow-picker': FlowPickerField,
    'enum-cards': EnumCardsField,
    duration: DurationField,
}

const visibleFields = computed(() =>
    props.fields.filter((field) => resolveVisibility(field.schema, props.rootConfig)),
)

function componentFor(field: FieldEntry): object {
    const type = String(field.schema.type ?? 'string')
    return FIELD_COMPONENTS[type] ?? TextField
}

function valueFor(field: FieldEntry): unknown {
    const stored = props.config[field.key]
    if (stored !== undefined) {
        return stored
    }
    return field.schema.default
}

function update(field: FieldEntry, value: unknown) {
    emit('update:config', {[field.key]: value})
}
</script>

<template>
    <div
        v-for="field in visibleFields"
        :key="field.key"
        class="config-field"
    >
        <div class="field-label">
            {{ field.schema.label ?? field.key }}
            <span v-if="field.required" class="schema-required">*</span>
        </div>
        <component
            :is="componentFor(field)"
            :root-config="rootConfig"
            :schema="field.schema"
            :value="valueFor(field)"
            @update:value="update(field, $event)"
        />
        <p v-if="field.schema.help" class="field-help">
            {{ field.schema.help }}
        </p>
    </div>
</template>

<style scoped>
.schema-required {
    color: var(--error);
    margin-left: 2px;
}

.field-help {
    font-size: 11px;
    color: var(--text-3);
    margin-top: 4px;
    line-height: 1.35;
}
</style>
