<script setup lang="ts">
import {ref} from 'vue'
import VariablePicker from '../VariablePicker.vue'
import {useInsertAtCursor} from '@builder/composables/useInsertAtCursor'

defineProps({
    value:  { type: [String, null] as unknown as () => string | null, default: '' },
    schema: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:value'])

// State-picker fields hold a path into flow state (`flow.foo`, `system.bar`,
// `contact.email`, ...). The variable picker drops in the leading `{{...}}`
// snippet — most engines accept the same template syntax for both
// templated text and bare path arguments, and stripping the braces is
// trivial in handlers when needed.
const inputRef = ref<HTMLInputElement | null>(null)
const insert   = useInsertAtCursor(inputRef, (next) => emit('update:value', next))
</script>

<template>
    <div class="field-with-picker">
        <input
            ref="inputRef"
            type="text"
            class="field-input state-picker-input"
            :value="value ?? ''"
            :placeholder="(schema as { placeholder?: string })?.placeholder ?? 'flow.foo'"
            @input="emit('update:value', ($event.target as HTMLInputElement).value)"
        >
        <VariablePicker
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
.state-picker-input {
    font-family: 'Victor Mono', monospace;
    font-size: 11.5px;
}
.field-picker {
    flex-shrink: 0;
}
</style>
