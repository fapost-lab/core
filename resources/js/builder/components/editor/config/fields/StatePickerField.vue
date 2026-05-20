<script setup lang="ts">
import {computed, ref} from 'vue'
import VariablePicker from '../VariablePicker.vue'
import StatePathPicker from '../StatePathPicker.vue'
import {useInsertAtCursor} from '@builder/composables/useInsertAtCursor'

interface StatePickerSchema {
    placeholder?: string
    /** Constrain suggestions to these namespaces (`['flow']`, `['flow','contact']`, …). */
    namespaces?: string[]
    /** When namespaces are declared, render a search-enabled popover. */
    searchable?: boolean
}

const props = defineProps({
    value:  { type: [String, null] as unknown as () => string | null, default: '' },
    schema: { type: Object as () => StatePickerSchema, default: () => ({}) },
})

const emit = defineEmits(['update:value'])

const namespaces = computed<string[]>(() =>
    Array.isArray(props.schema?.namespaces) ? props.schema.namespaces : [],
)

// Strict dropdown mode kicks in when the handler author declares allowed
// namespaces — author picks one of the existing user variables instead of
// hand-typing a path.
const strictMode = computed(() => namespaces.value.length > 0)

// Free-text mode keeps the legacy snippet-insert UX: monospace input plus a
// VariablePicker. Most engines accept template syntax for bare path args, so
// inserted `{{...}}` snippets are handled the same way.
const inputRef = ref<HTMLInputElement | null>(null)
const insert   = useInsertAtCursor(inputRef, (next) => emit('update:value', next))

function onStrictUpdate(next: string) {
    emit('update:value', next)
}
</script>

<template>
    <StatePathPicker
        v-if="strictMode"
        :model-value="value ?? ''"
        :namespaces="namespaces"
        :allow-manual="false"
        :placeholder="schema?.placeholder"
        @update:model-value="onStrictUpdate"
    />

    <div v-else class="field-with-picker">
        <input
            ref="inputRef"
            type="text"
            class="field-input state-picker-input"
            :value="value ?? ''"
            :placeholder="schema?.placeholder ?? 'flow.foo'"
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
