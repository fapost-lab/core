<script setup lang="ts">
/**
 * Single-line (or multiline) text input with an attached VariablePicker that
 * inserts `{{...}}` snippets at the cursor. Self-contained so it can be used
 * inside v-for repeaters where each row needs its own cursor ref.
 */
import {ref} from 'vue'
import VariablePicker from '../VariablePicker.vue'
import {useInsertAtCursor} from '@builder/composables/useInsertAtCursor'

const props = defineProps({
    modelValue:  { type: String, default: '' },
    placeholder: { type: String, default: '' },
    multiline:   { type: Boolean, default: false },
    mono:        { type: Boolean, default: false },
    rows:        { type: Number, default: 4 },
})

const emit = defineEmits(['update:modelValue'])

const inputRef = ref<HTMLInputElement | HTMLTextAreaElement | null>(null)
const insert   = useInsertAtCursor(inputRef, (next) => emit('update:modelValue', next))
</script>

<template>
    <div class="template-input" :class="{ multiline }">
        <textarea
            v-if="multiline"
            ref="inputRef"
            class="field-input"
            :class="{ mono }"
            :rows="rows"
            :value="props.modelValue"
            :placeholder="placeholder"
            @input="emit('update:modelValue', ($event.target as HTMLTextAreaElement).value)"
        />
        <input
            v-else
            ref="inputRef"
            class="field-input"
            :class="{ mono }"
            type="text"
            :value="props.modelValue"
            :placeholder="placeholder"
            @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
        >
        <VariablePicker class="template-picker" @select="insert" />
    </div>
</template>

<style scoped>
.template-input {
    position: relative;
    flex: 1;
}
.field-input {
    width: 100%;
    padding: 6px 28px 6px 9px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface-2);
    font-family: var(--font-sans);
    font-size: 12.5px;
    color: var(--text);
    outline: none;
}
.field-input.mono {
    font-family: var(--font-mono);
    font-size: 12px;
}
.field-input:focus { border-color: var(--primary); background: var(--paper); }
.template-picker {
    position: absolute;
    top: 5px;
    right: 5px;
    z-index: 1;
}
.multiline .template-picker { top: 6px; }
</style>
