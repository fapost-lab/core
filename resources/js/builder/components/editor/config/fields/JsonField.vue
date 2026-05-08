<script setup lang="ts">
import {computed, ref, watch} from 'vue'

const props = defineProps({
    value:  { type: [Object, Array, null] as unknown as () => unknown, default: () => ({}) },
    schema: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:value'])

// Local raw text so the user can type partially-valid JSON without us
// fighting the cursor on every keystroke. The committed value is parsed
// only when the textarea blurs or the JSON is structurally valid.
const raw = ref<string>(serialize(props.value))
const error = ref<string | null>(null)

watch(
    () => props.value,
    (next) => {
        const serialized = serialize(next)
        if (serialized !== raw.value) {
            raw.value = serialized
            error.value = null
        }
    },
)

function serialize(value: unknown): string {
    if (value === null || value === undefined) return ''
    try {
        return JSON.stringify(value, null, 2)
    } catch {
        return ''
    }
}

function onInput(event: Event) {
    const text = (event.target as HTMLTextAreaElement).value
    raw.value = text

    if (text.trim() === '') {
        error.value = null
        emit('update:value', schemaDefault())
        return
    }

    try {
        const parsed = JSON.parse(text)
        error.value = null
        emit('update:value', parsed)
    } catch (e) {
        error.value = (e as Error).message
        // Keep the parsed form unchanged until input is valid again — avoids
        // wiping the previous good config when the user is mid-edit.
    }
}

function onBlur() {
    if (error.value === null && raw.value.trim() !== '') {
        // Reformat to canonical pretty-print on blur.
        raw.value = serialize(props.value)
    }
}

function schemaDefault(): unknown {
    return (props.schema as { default?: unknown }).default ?? null
}

const inputClass = computed(() => [
    'w-full rounded border px-3 py-1.5 text-xs font-mono resize-none focus:outline-none',
    error.value
        ? 'border-red-300 focus:border-red-400'
        : 'border-gray-200 focus:border-blue-400',
])
</script>

<template>
    <div class="flex flex-col gap-1">
        <textarea
            :class="inputClass"
            rows="6"
            spellcheck="false"
            :value="raw"
            :placeholder="(schema as { placeholder?: string })?.placeholder ?? '{}'"
            @input="onInput"
            @blur="onBlur"
        />
        <div v-if="error" class="text-xs text-red-500">
            {{ error }}
        </div>
    </div>
</template>
