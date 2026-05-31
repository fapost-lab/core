<script setup lang="ts">
/**
 * Operand picker for a single Branch rule.
 *
 * Shows the selected variable path in a read-only display field with a
 * VariablePicker trigger ({...}) on the right — same UX as other variable
 * fields in the builder. Selecting a variable via the picker decompiles the
 * path into the structured BranchOperandUiState that the backend handler reads.
 */
import {computed} from 'vue'
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

function onSelect(snippet: string) {
    // VariablePicker emits {{flow.name}} — strip the braces to get the raw path.
    const path = snippet.replace(/^\{\{\s*/, '').replace(/\s*\}\}$/, '').trim()
    emit('update:modelValue', decompileLeft(path, userVars.value))
}

function clear() {
    emit('update:modelValue', { mode: 'user_variable', variable: null, source: null, field: null, rawPath: null })
}
</script>

<template>
    <div class="operand-row">
        <div class="operand-display" :class="{ empty: !displayPath }">
            <span class="operand-path">{{ displayPath || '— pick variable —' }}</span>
            <button v-if="displayPath" type="button" class="clear-btn" title="Clear" @click.stop="clear">×</button>
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
    padding: 5px 8px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface-2);
    min-height: 32px;
    cursor: default;
}

.operand-display.empty {
    border-style: dashed;
}

.operand-path {
    font-family: 'Victor Mono', monospace;
    font-size: 12px;
    color: var(--text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.operand-display.empty .operand-path {
    color: var(--text-3);
    font-family: 'DM Sans', sans-serif;
    font-size: 12.5px;
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
