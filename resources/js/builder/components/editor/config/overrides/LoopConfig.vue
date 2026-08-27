<script setup lang="ts">
/**
 * Loop node config — counted (N iterations) or while (condition) mode.
 *
 * Counted source is either a fixed number ({ type: 'literal', value }) or a
 * structured operand ({ ref, ... }) resolved at runtime. While mode reuses the
 * branch operand picker + operator/value for the continuation condition.
 *
 * The paired loop_end node is auto-managed by the builder, so it has no UI here.
 */
import {computed, ref, watch} from 'vue'
import AccordionSection from '../AccordionSection.vue'
import ConditionOperandPicker from './ConditionOperandPicker.vue'
import type {BranchOperandUiState} from '@builder/utils/branchOperandCompiler'
import {compileLeft, decompileLeft} from '@builder/utils/branchOperandCompiler'
import {useFlowVariables} from '@builder/composables/useFlowVariables'

const props = defineProps({
    node:   {type: Object as () => Record<string, unknown>, required: true},
    schema: {type: Object as () => Record<string, unknown>, required: true},
})

const emit = defineEmits(['update:config'])

const OPERATORS = [
    {value: 'eq', label: '= equals'},
    {value: 'neq', label: '≠ not equals'},
    {value: 'gt', label: '> greater than'},
    {value: 'gte', label: '≥ greater or equal'},
    {value: 'lt', label: '< less than'},
    {value: 'lte', label: '≤ less or equal'},
    {value: 'contains', label: 'contains'},
    {value: 'in', label: 'in list'},
    {value: 'empty', label: 'is empty'},
    {value: 'not_empty', label: 'is not empty'},
]
const UNARY_OPS = new Set(['empty', 'not_empty'])

const {userVars} = useFlowVariables()

function config(): Record<string, unknown> {
    return (props.node.config ?? {}) as Record<string, unknown>
}

const mode = ref<'counted' | 'while'>('counted')
const iteratorName = ref('iterator')
// Counted: fixed number vs variable/source operand.
const countMode = ref<'fixed' | 'variable'>('fixed')
const countLiteral = ref('')
const countOperand = ref<BranchOperandUiState>(decompileLeft(null, userVars.value))
// While: condition.
const conditionOperand = ref<BranchOperandUiState>(decompileLeft(null, userVars.value))
const operator = ref('eq')
const conditionValue = ref('')

function load() {
    const cfg = config()
    mode.value = cfg.mode === 'while' ? 'while' : 'counted'
    iteratorName.value = typeof cfg.iterator_name === 'string' && cfg.iterator_name !== '' ? cfg.iterator_name : 'iterator'

    const countSource = cfg.count_source as Record<string, unknown> | undefined
    if (countSource && countSource.type === 'literal') {
        countMode.value = 'fixed'
        countLiteral.value = countSource.value !== undefined && countSource.value !== null ? String(countSource.value) : ''
        countOperand.value = decompileLeft(null, userVars.value)
    } else if (countSource && typeof countSource === 'object') {
        countMode.value = 'variable'
        countOperand.value = decompileLeft(countSource, userVars.value)
    } else {
        countMode.value = 'fixed'
        countLiteral.value = ''
    }

    const condition = cfg.condition as Record<string, unknown> | undefined
    conditionOperand.value = decompileLeft(condition?.left ?? null, userVars.value)
    operator.value = typeof condition?.operator === 'string' ? condition.operator : 'eq'
    conditionValue.value = condition?.value !== undefined && condition?.value !== null ? String(condition.value) : ''
}

watch(() => props.node.id, load, {immediate: true})

function persist() {
    const patch: Record<string, unknown> = {
        mode:          mode.value,
        iterator_name: iteratorName.value.trim() === '' ? 'iterator' : iteratorName.value.trim(),
    }

    if (mode.value === 'counted') {
        patch.count_source = countMode.value === 'fixed'
            ? {type: 'literal', value: countLiteral.value.trim() === '' ? 0 : Number(countLiteral.value)}
            : compileLeft(countOperand.value)
        patch.condition = undefined
    } else {
        patch.condition = {
            left:     compileLeft(conditionOperand.value),
            operator: operator.value,
            value:    UNARY_OPS.has(operator.value) ? undefined : conditionValue.value,
        }
        patch.count_source = undefined
    }

    emit('update:config', patch)
}

const isUnary = computed(() => UNARY_OPS.has(operator.value))

// Built in script to avoid literal `{{ }}` inside the template (which would
// prematurely close the Vue interpolation).
const iteratorHint = computed(() => {
    const name = iteratorName.value.trim() === '' ? 'iterator' : iteratorName.value.trim()
    const open = '{{'
    const close = '}}'
    const total = mode.value === 'counted' ? `, total as ${open}flow.${name}_total${close}` : ''
    return `Available in messages as ${open}flow.${name}${close} (1-based)${total}.`
})
</script>

<template>
    <div class="loop-config">
        <AccordionSection title="Loop mode" default-open>
            <div class="config-field">
                <label class="lc-radio">
                    <input type="radio" value="counted" :checked="mode === 'counted'" @change="mode = 'counted'; persist()">
                    <span><strong>Counted</strong> — repeat a fixed number of times</span>
                </label>
                <label class="lc-radio">
                    <input type="radio" value="while" :checked="mode === 'while'" @change="mode = 'while'; persist()">
                    <span><strong>While</strong> — repeat while a condition is true</span>
                </label>
            </div>
        </AccordionSection>

        <AccordionSection v-if="mode === 'counted'" title="Iterations" default-open>
            <div class="config-field">
                <label class="lc-radio">
                    <input type="radio" value="fixed" :checked="countMode === 'fixed'" @change="countMode = 'fixed'; persist()">
                    <span>Fixed number</span>
                </label>
                <input
                    v-if="countMode === 'fixed'"
                    class="field-input lc-indent"
                    type="number"
                    min="0"
                    placeholder="5"
                    :value="countLiteral"
                    @input="countLiteral = ($event.target as HTMLInputElement).value; persist()"
                >
                <label class="lc-radio">
                    <input type="radio" value="variable" :checked="countMode === 'variable'" @change="countMode = 'variable'; persist()">
                    <span>From a variable</span>
                </label>
                <div v-if="countMode === 'variable'" class="lc-indent">
                    <ConditionOperandPicker
                        :model-value="countOperand"
                        @update:model-value="(s: BranchOperandUiState) => { countOperand = s; persist() }"
                    />
                </div>
            </div>
        </AccordionSection>

        <AccordionSection v-else title="Condition" default-open>
            <div class="config-field">
                <div class="field-label">Variable</div>
                <ConditionOperandPicker
                    :model-value="conditionOperand"
                    @update:model-value="(s: BranchOperandUiState) => { conditionOperand = s; persist() }"
                />
            </div>
            <div class="config-field">
                <div class="field-label">Operator</div>
                <select
                    class="field-input"
                    :value="operator"
                    @change="operator = ($event.target as HTMLSelectElement).value; persist()"
                >
                    <option v-for="op in OPERATORS" :key="op.value" :value="op.value">{{ op.label }}</option>
                </select>
            </div>
            <div v-if="!isUnary" class="config-field">
                <div class="field-label">Value</div>
                <input
                    class="field-input"
                    placeholder="value"
                    :value="conditionValue"
                    @input="conditionValue = ($event.target as HTMLInputElement).value; persist()"
                >
            </div>
        </AccordionSection>

        <AccordionSection title="Advanced">
            <div class="config-field">
                <div class="field-label">Iterator name</div>
                <input
                    class="field-input"
                    placeholder="iterator"
                    :value="iteratorName"
                    @input="iteratorName = ($event.target as HTMLInputElement).value; persist()"
                >
                <div class="lc-hint">{{ iteratorHint }}</div>
            </div>
        </AccordionSection>
    </div>
</template>

<style scoped>
.loop-config {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.config-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin-bottom: 4px;
}
.field-label {
    font-size: 12px;
    color: var(--text-2);
}
.field-input {
    box-sizing: border-box;
    width: 100%;
    padding: 6px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
    color: var(--text);
    font-size: 13px;
    font-family: inherit;
}
/* Indented controls sit 22px in — shrink so the right edge stays aligned. */
input.field-input.lc-indent {
    width: calc(100% - 22px);
}
.field-input:focus {
    outline: none;
    border-color: var(--primary);
}
.lc-radio {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    font-size: 12.5px;
    color: var(--text);
    cursor: pointer;
}
.lc-radio input {
    margin-top: 2px;
    cursor: pointer;
}
.lc-indent {
    margin-left: 22px;
}
.lc-hint {
    font-size: 11px;
    color: var(--text-3);
    line-height: 1.4;
}
.lc-hint code {
    font-family: 'Victor Mono', monospace;
    font-size: 10.5px;
    background: var(--surface-2);
    padding: 1px 4px;
    border-radius: 4px;
}
</style>
