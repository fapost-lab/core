<script setup lang="ts">
/**
 * Operand picker for a single Branch rule. Renders in two modes:
 *  - user_variable (default): a flat dropdown of user-defined variables
 *    plus a "+ Other source..." entry that switches to source mode.
 *  - source: a source-kind dropdown (Contact / RAG / API / System / Module),
 *    plus a field dropdown scoped to the chosen source.
 */
import {computed} from 'vue'
import {useFlowVariables} from '@builder/composables/useFlowVariables'
import {useConditionSources} from '@builder/composables/useConditionSources'
import type {BranchOperandUiState} from '@builder/utils/branchOperandCompiler'
import type {Variable} from '@builder/dto/types'

const props = defineProps<{
    modelValue: BranchOperandUiState
}>()

const emit = defineEmits<{
    (e: 'update:modelValue', value: BranchOperandUiState): void
}>()

const { userVars } = useFlowVariables()
const { sources, findSource } = useConditionSources()

const SWITCH_TO_SOURCE = '__switch_to_source__'

const userVariableValue = computed(() => {
    if (props.modelValue.mode !== 'user_variable' || !props.modelValue.variable) {
        return ''
    }
    const v = props.modelValue.variable
    return variableKey(v)
})

function variableKey(v: Variable): string {
    return [v.storage, v.group ?? '', v.name].join('|')
}

function variableLabelFor(v: { name: string; storage: string; group: string | null }): string {
    if (v.storage === 'session') {
        return `${v.name} (Temporary)`
    }
    if (v.group) {
        return `${v.group}.${v.name} (Contact)`
    }
    return `${v.name} (Contact)`
}

function onUserVariableChange(event: Event) {
    const value = (event.target as HTMLSelectElement).value

    if (value === SWITCH_TO_SOURCE) {
        emit('update:modelValue', { mode: 'source', variable: null, source: null, field: null, rawPath: null })
        return
    }

    const found = userVars.value.find((pv) => {
        if (pv.source.kind === 'temporary') {
            return variableKey({ name: pv.pathSegments.at(-1)!, type: 'text', storage: 'session', group: null }) === value
        }
        if (pv.source.kind === 'contact-profile') {
            return variableKey({
                name:    pv.pathSegments.at(-1)!,
                type:    'text',
                storage: 'contact',
                group:   pv.group,
            }) === value
        }
        return false
    })

    if (!found) {
        emit('update:modelValue', { ...props.modelValue, variable: null })
        return
    }

    const variable: Variable = found.source.kind === 'temporary'
        ? { name: found.pathSegments.at(-1)!, type: 'text', storage: 'session', group: null }
        : { name: found.pathSegments.at(-1)!, type: 'text', storage: 'contact', group: found.group }

    emit('update:modelValue', {
        mode:    'user_variable',
        variable,
        source:  null,
        field:   null,
        rawPath: null,
    })
}

function onSourceChange(event: Event) {
    const value = (event.target as HTMLSelectElement).value
    emit('update:modelValue', {
        mode:    'source',
        variable: null,
        source:  value === '' ? null : value,
        field:   null,
        rawPath: null,
    })
}

function onFieldChange(event: Event) {
    const value = (event.target as HTMLSelectElement).value
    emit('update:modelValue', {
        ...props.modelValue,
        mode:  'source',
        field: value === '' ? null : value,
    })
}

function backToUserVariable() {
    emit('update:modelValue', {
        mode:     'user_variable',
        variable: null,
        source:   null,
        field:    null,
        rawPath:  null,
    })
}

const currentSource = computed(() => findSource(props.modelValue.source))
</script>

<template>
    <div class="operand-picker">
        <!-- user_variable mode -->
        <template v-if="modelValue.mode === 'user_variable'">
            <select
                class="field-input"
                :value="userVariableValue"
                @change="onUserVariableChange"
            >
                <option value="">— pick variable —</option>
                <optgroup v-if="userVars.length > 0" label="User variables">
                    <option
                        v-for="pv in userVars"
                        :key="pv.path"
                        :value="variableKey(
                            pv.source.kind === 'temporary'
                                ? { name: pv.pathSegments.at(-1)!, type: 'text', storage: 'session', group: null }
                                : { name: pv.pathSegments.at(-1)!, type: 'text', storage: 'contact', group: pv.group }
                        )"
                    >
                        {{ variableLabelFor(
                            pv.source.kind === 'temporary'
                                ? { name: pv.pathSegments.at(-1)!, storage: 'session', group: null }
                                : { name: pv.pathSegments.at(-1)!, storage: 'contact', group: pv.group }
                        ) }}
                    </option>
                </optgroup>
                <option :value="SWITCH_TO_SOURCE">+ Other source...</option>
            </select>
        </template>

        <!-- source mode -->
        <template v-else>
            <div class="source-picker">
                <select class="field-input" :value="modelValue.source ?? ''" @change="onSourceChange">
                    <option value="">— pick source —</option>
                    <option v-for="s in sources" :key="s.id" :value="s.id">
                        {{ s.icon }} {{ s.label }}
                    </option>
                </select>

                <select
                    v-if="currentSource"
                    class="field-input"
                    :value="modelValue.field ?? ''"
                    @change="onFieldChange"
                >
                    <option value="">— pick field —</option>
                    <option v-for="f in currentSource.fields" :key="f.id" :value="f.id">
                        {{ f.label }}
                    </option>
                </select>
            </div>

            <button
                type="button"
                class="back-btn"
                @click="backToUserVariable"
            >
                ← Back to user variables
            </button>

            <div v-if="modelValue.rawPath" class="warn">
                Unknown source: <code>{{ modelValue.rawPath }}</code>
            </div>
        </template>
    </div>
</template>

<style scoped>
.operand-picker { display: flex; flex-direction: column; gap: 4px; }
.source-picker { display: flex; gap: 4px; }
.source-picker .field-input { flex: 1; }
.field-input {
    width: 100%;
    padding: 6px 9px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface-2);
    font-family: 'DM Sans', sans-serif;
    font-size: 12.5px;
    color: var(--text);
    outline: none;
    transition: border-color .15s;
}
.field-input:focus { border-color: var(--primary); background: #fff; }
.back-btn {
    align-self: flex-start;
    background: transparent;
    border: none;
    color: var(--text-3);
    cursor: pointer;
    font-size: 11.5px;
    padding: 2px 0;
}
.back-btn:hover { color: var(--primary); }
.warn {
    font-size: 11.5px;
    color: var(--amber, #b8860b);
    background: var(--amber-bg, #fff8e1);
    padding: 4px 6px;
    border-radius: 4px;
}
.warn code { font-family: 'Victor Mono', monospace; }
</style>
