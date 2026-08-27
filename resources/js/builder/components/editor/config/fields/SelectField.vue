<script setup lang="ts">
import {computed} from 'vue'

const props = defineProps({
    value:  { type: String, default: '' },
    schema: { type: Object, required: true },
})

defineEmits(['update:value'])

// Backward-compat: PHP NodeHandler::configSchema returns `options`, while
// some legacy schemas use `values`. Accept either, normalize to a list of
// `{ value, label }` pairs so the UI can label entries when the schema
// provides a map shape (e.g. `{ http: 'HTTP webhook', handler: 'Action' }`).
const choices = computed<Array<{ value: string; label: string }>>(() => {
    const raw = (props.schema as { options?: unknown; values?: unknown }).options
        ?? (props.schema as { options?: unknown; values?: unknown }).values
        ?? []

    if (Array.isArray(raw)) {
        return raw.map((entry) => ({ value: String(entry), label: String(entry) }))
    }

    if (raw && typeof raw === 'object') {
        return Object.entries(raw as Record<string, unknown>).map(([value, label]) => ({
            value,
            label: String(label),
        }))
    }

    return []
})
</script>

<template>
    <select
        class="field-input"
        :value="value ?? ''"
        @change="$emit('update:value', ($event.target as HTMLSelectElement).value)"
    >
        <option value="">—</option>
        <option
            v-for="opt in choices"
            :key="opt.value"
            :value="opt.value"
        >
            {{ opt.label }}
        </option>
    </select>
</template>
