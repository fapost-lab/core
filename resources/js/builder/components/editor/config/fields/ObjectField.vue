<script lang="ts" setup>
import {computed} from 'vue'
import type {FieldEntry} from '../SchemaFields.vue'
import SchemaFields from '../SchemaFields.vue'

/**
 * Nested object field. The schema declares an inner `fields: {...}` map
 * with the same vocabulary as the top-level configSchema; this component
 * re-uses SchemaFields to render that sub-schema against a sub-config.
 * Updates are emitted as the full sub-object so the parent never has to
 * deep-merge — replace-the-slot is enough.
 */
const props = defineProps({
    value: {type: Object as () => Record<string, unknown> | null | undefined, default: () => ({})},
    schema: {type: Object as () => Record<string, unknown>, default: () => ({})},
    rootConfig: {type: Object as () => Record<string, unknown>, default: () => ({})},
})

const emit = defineEmits(['update:value'])

const innerConfig = computed<Record<string, unknown>>(() => {
    const v = props.value
    return v && typeof v === 'object' ? (v as Record<string, unknown>) : {}
})

const fields = computed<FieldEntry[]>(() => {
    const raw = (props.schema.fields ?? {}) as Record<string, unknown>
    const requiredList = Array.isArray(props.schema.required)
        ? (props.schema.required as string[])
        : []

    const entries: FieldEntry[] = []
    for (const [key, fieldSchemaRaw] of Object.entries(raw)) {
        if (!fieldSchemaRaw || typeof fieldSchemaRaw !== 'object') continue
        const fieldSchema = fieldSchemaRaw as Record<string, unknown>
        entries.push({
            key,
            schema: fieldSchema,
            required: Boolean(fieldSchema.required) || requiredList.includes(key),
        })
    }
    return entries
})

function applyPatch(patch: Record<string, unknown>) {
    const next = {...innerConfig.value, ...patch}
    // Strip keys explicitly set to `undefined` so the persisted JSON
    // doesn't accumulate dead entries when an inner field is cleared.
    for (const [k, v] of Object.entries(patch)) {
        if (v === undefined) delete next[k]
    }
    emit('update:value', next)
}
</script>

<template>
    <div class="object-field">
        <SchemaFields
            :config="innerConfig"
            :fields="fields"
            :root-config="rootConfig"
            @update:config="applyPatch"
        />
    </div>
</template>

<style scoped>
.object-field {
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 10px 12px;
    background: var(--surface-2);
    display: flex;
    flex-direction: column;
    gap: 8px;
}
</style>
