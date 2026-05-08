<script setup lang="ts">
import {ref} from 'vue'
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
</script>

<template>
    <div class="field-with-picker">
        <input
            ref="inputRef"
            class="w-full rounded border border-gray-200 px-3 py-1.5 text-sm focus:outline-none focus:border-blue-400"
            :type="isNumber ? 'number' : 'text'"
            :value="value ?? ''"
            :placeholder="(schema as { placeholder?: string })?.placeholder ?? ''"
            @input="onInput"
        >
        <VariablePicker
            v-if="showPicker"
            class="field-picker"
            @select="insert"
        />
    </div>
</template>

<style scoped>
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
</style>
