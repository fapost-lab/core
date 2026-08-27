<script setup lang="ts">
import {computed, ref} from 'vue'
import VariablePicker from '../VariablePicker.vue'
import {useInsertAtCursor} from '@builder/composables/useInsertAtCursor'

const props = defineProps({
    value:  { type: [String, Number, null] as unknown as () => string | number | null, default: '' },
    schema: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:value'])

const inputRef = ref<HTMLInputElement | null>(null)

// Number inputs don't accept template snippets — only plain strings. We
// keep the picker available for `string`/`state-picker` types and hide it
// for `number`. The schema signals the intent through `schema.type`.
const isNumber  = (props.schema as { type?: string })?.type === 'number'
const showPicker = !isNumber

const insert = useInsertAtCursor(inputRef, (next) => emit('update:value', next))

function onInput(event: Event) {
    const raw = (event.target as HTMLInputElement).value
    if (isNumber) {
        emit('update:value', raw === '' ? null : Number(raw))
        return
    }
    emit('update:value', raw)
}

/**
 * Inline validation feedback. The backend validator stays the source of
 * truth at save time — this just surfaces obvious mistakes earlier so
 * authors don't bounce between the editor and the Publish error toast
 * for typos in regex-shaped fields (event_type, etc.).
 */
const validationError = computed<string | null>(() => {
    const v = props.value
    if (v === null || v === undefined || v === '') return null

    const s = props.schema as Record<string, unknown>

    if (isNumber && typeof v === 'number') {
        if (typeof s.min === 'number' && v < (s.min as number)) {
            return `Must be ≥ ${s.min}`
        }
        if (typeof s.max === 'number' && v > (s.max as number)) {
            return `Must be ≤ ${s.max}`
        }
        return null
    }

    if (!isNumber && typeof v === 'string' && typeof s.regex === 'string') {
        try {
            const re = new RegExp(s.regex as string)
            if (!re.test(v)) {
                return typeof s.regex_message === 'string'
                    ? (s.regex_message as string)
                    : `Does not match ${s.regex}`
            }
        } catch {
            // Invalid regex in schema — silently skip; backend will flag.
        }
    }

    return null
})
</script>

<template>
    <div class="field-wrapper">
        <div class="field-with-picker">
            <input
                ref="inputRef"
                :class="{ 'field-input--error': validationError !== null }"
                :placeholder="(schema as { placeholder?: string })?.placeholder ?? ''"
                :type="isNumber ? 'number' : 'text'"
                :value="value ?? ''"
                class="field-input"
                @input="onInput"
            >
            <VariablePicker
                v-if="showPicker"
                class="field-picker"
                @select="insert"
            />
        </div>
        <p v-if="validationError" class="field-error">{{ validationError }}</p>
    </div>
</template>

<style scoped>
.field-wrapper {
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.field-with-picker {
    display: flex;
    align-items: center;
    gap: 6px;
}
.field-with-picker > input {
    flex: 1;
    min-width: 0;
}
.field-picker {
    flex-shrink: 0;
}

.field-input--error {
    border-color: var(--rose);
}

.field-error {
    font-size: 11px;
    color: var(--rose);
    line-height: 1.35;
    margin: 0;
}
</style>
