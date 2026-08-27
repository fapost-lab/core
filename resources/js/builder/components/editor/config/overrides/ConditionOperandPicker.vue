<script setup lang="ts">
/**
 * Operand picker for a single Branch rule.
 *
 * Shows the selected variable path in a read-only display field with a
 * VariablePicker trigger ({...}) on the right — same UX as other variable
 * fields in the builder. Selecting a variable via the picker decompiles the
 * path into the structured BranchOperandUiState that the backend handler reads.
 */
import {computed, ref, watch} from 'vue'
import VariablePicker from '@builder/components/editor/config/VariablePicker.vue'
import {useFlowVariables} from '@builder/composables/useFlowVariables'
import type {BranchOperandUiState} from '@builder/utils/branchOperandCompiler'
import {decompileLeft} from '@builder/utils/branchOperandCompiler'

const props = defineProps<{
    modelValue: BranchOperandUiState
}>()

const emit = defineEmits<{
    (e: 'update:modelValue', value: BranchOperandUiState): void
}>()

const { userVars } = useFlowVariables()

/** Human-readable path for the current state, shown in the display field. */
const displayPath = computed((): string => {
    const s = props.modelValue
    if (s.rawPath) return s.rawPath
    if (s.mode === 'user_variable' && s.variable) {
        const v = s.variable
        if (v.storage === 'contact') {
            return v.group ? `contact.${v.group}.${v.name}` : `contact.${v.name}`
        }
        return `flow.${v.name}`
    }
    if (s.mode === 'source' && s.source && s.field) {
        return `${s.source}.${s.field}`
    }
    return ''
})

// Local text mirrors exactly what the user types. We decompile from it to emit
// state, but never overwrite it from the round-tripped `displayPath` while the
// field is focused — otherwise normalisation (e.g. dropping a trailing dot in
// `call.last.body.`) would fight the keystroke. Sync only on external changes.
const text = ref(displayPath.value)
const focused = ref(false)

watch(displayPath, (next) => {
    if (!focused.value) text.value = next
})

function onSelect(snippet: string) {
    // VariablePicker emits {{flow.name}} — strip the braces to get the raw path.
    const path = snippet.replace(/^\{\{\s*/, '').replace(/\s*\}\}$/, '').trim()
    emit('update:modelValue', decompileLeft(path, userVars.value))
}

// Free typing: decompile the entered path on every change. Known variables /
// sources map to structured state; anything else (deep dot-paths) is kept as a
// raw path and compiled to a legacy string the backend resolves via data_get.
function onType(event: Event) {
    text.value = (event.target as HTMLInputElement).value
    emit('update:modelValue', decompileLeft(text.value, userVars.value))
}

function onBlur() {
    focused.value = false
    text.value = displayPath.value
}

function clear() {
    text.value = ''
    emit('update:modelValue', { mode: 'user_variable', variable: null, source: null, field: null, rawPath: null })
}
</script>

<template>
    <div class="operand-row">
        <div class="operand-display" :class="{ empty: !text }">
            <input
                class="operand-input"
                type="text"
                :value="text"
                placeholder="variable or path, e.g. call.last.body"
                autocomplete="off"
                spellcheck="false"
                @input="onType"
                @focus="focused = true"
                @blur="onBlur"
            >
            <button v-if="text" type="button" class="clear-btn" title="Clear" @click.stop="clear">×</button>
        </div>
        <VariablePicker @select="onSelect" />
    </div>
</template>

<style scoped>
.operand-row {
    display: flex;
    align-items: center;
    gap: 4px;
    flex: 1;
}

.operand-display {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 6px 0 0;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface);
    min-height: 32px;
}

.operand-display.empty {
    border-style: dashed;
}

.operand-input {
    flex: 1;
    min-width: 0;
    padding: 5px 8px;
    border: none;
    background: transparent;
    font-family: 'Victor Mono', monospace;
    font-size: 12px;
    color: var(--text);
}
.operand-input:focus {
    outline: none;
}
.operand-input::placeholder {
    font-family: 'DM Sans', sans-serif;
    font-size: 12.5px;
    color: var(--text-3);
}

.clear-btn {
    background: transparent;
    border: none;
    color: var(--text-3);
    cursor: pointer;
    font-size: 14px;
    padding: 0 2px;
    line-height: 1;
    flex-shrink: 0;
}

.clear-btn:hover { color: var(--rose, #e05252); }
</style>
