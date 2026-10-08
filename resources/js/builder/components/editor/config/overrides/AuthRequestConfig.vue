<script setup lang="ts">
/**
 * auth_request node config — a single "authenticate when <operand> <op> <value>"
 * rule, reusing the Branch operand picker and operator vocabulary. On a match
 * the contact is flagged authenticated and the flow takes the `authenticated`
 * output; otherwise `failed`. Only the `basic` method exists today, so the
 * method selector is hidden and written implicitly.
 */
import {computed} from 'vue'
import AccordionSection from '../AccordionSection.vue'
import ConditionOperandPicker from './ConditionOperandPicker.vue'
import {type BranchOperandUiState, compileLeft, decompileLeft} from '@builder/utils/branchOperandCompiler'
import {useFlowVariables} from '@builder/composables/useFlowVariables'
import {useTranslations} from '@builder/composables/useTranslations'

const props = defineProps<{
    node:   { id: string; config?: Record<string, unknown> }
    schema: Record<string, unknown>
}>()

const emit = defineEmits<{
    (e: 'update:config', patch: Record<string, unknown>): void
}>()

const {t} = useTranslations()
const {userVars} = useFlowVariables()

// Full operator vocabulary, mirrored from BranchOperator. Kept self-sufficient
// (not dependent on the schema payload) so every operator is always offered;
// labels are localized via builder.operators.*.
const OPERATOR_VALUES = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'contains', 'in', 'empty', 'not_empty'] as const

const UNARY_OPS = new Set(['empty', 'not_empty'])

const config = computed<Record<string, unknown>>(() => props.node.config ?? {})

const operandState = computed<BranchOperandUiState>(() =>
    decompileLeft((config.value.left ?? config.value.variable ?? null) as never, userVars.value),
)

const operator = computed<string>(() =>
    typeof config.value.operator === 'string' ? config.value.operator : 'eq',
)

const valueString = computed<string>(() => {
    const v = config.value.value
    if (v === undefined || v === null) {
        return ''
    }
    return typeof v === 'string' ? v : String(v)
})

const operatorOptions = computed<Array<{ value: string; label: string }>>(() =>
    OPERATOR_VALUES.map((value) => ({value, label: t(`operators.${value}`)})),
)

const isUnary = computed<boolean>(() => UNARY_OPS.has(operator.value))

// Always (re)assert method='basic' so the node config is complete even if the
// author never touches a "method" control.
function patch(p: Record<string, unknown>) {
    emit('update:config', {method: 'basic', ...p})
}

function onOperand(state: BranchOperandUiState) {
    patch({left: compileLeft(state)})
}

function onOperator(e: Event) {
    patch({operator: (e.target as HTMLSelectElement).value})
}

function onValue(e: Event) {
    patch({value: (e.target as HTMLInputElement).value})
}
</script>

<template>
    <div class="accordion">
        <AccordionSection :title="t('nodes.auth_request.section')" default-open>
            <div class="config-field">
                <div class="field-label">{{ t('nodes.auth_request.condition_label') }}</div>
                <ConditionOperandPicker
                    :model-value="operandState"
                    @update:model-value="onOperand"
                />
            </div>

            <div class="cond-row">
                <select class="field-input op-sel" :value="operator" @change="onOperator">
                    <option v-for="op in operatorOptions" :key="op.value" :value="op.value">{{ op.label }}</option>
                </select>
                <input
                    v-if="!isUnary"
                    type="text"
                    class="field-input val-input"
                    placeholder="1234"
                    :value="valueString"
                    @input="onValue"
                >
            </div>

            <p class="auth-hint">{{ t('nodes.auth_request.flag_hint') }}</p>
        </AccordionSection>

        <AccordionSection title="Meta">
            <div class="config-field">
                <div class="field-label">Node ID</div>
                <input
                    class="field-input"
                    style="font-family:var(--font-mono);font-size:11.5px"
                    :value="props.node.id"
                    readonly
                >
            </div>
        </AccordionSection>
    </div>
</template>

<style scoped>
.config-field {
    margin-bottom: 10px;
}
.field-label {
    font-size: 12px;
    color: var(--text-2);
    margin-bottom: 4px;
}
.cond-row {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 8px;
}
.field-input {
    padding: 6px 8px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface);
    font-size: 13px;
    color: var(--text);
    outline: none;
}
.field-input:focus {
    border-color: var(--primary);
}
.op-sel {
    flex: 0 0 170px;
}
.val-input {
    flex: 1;
    min-width: 0;
}
.auth-hint {
    margin: 4px 0 0;
    font-size: 11.5px;
    color: var(--text-3);
    line-height: 1.4;
}
</style>
