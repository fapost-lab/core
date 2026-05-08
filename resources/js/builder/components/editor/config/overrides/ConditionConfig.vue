<script setup lang="ts">
/**
 * Branch (Condition) node config — rule builder with structured operand picker.
 *
 * Each rule has:
 *   - structured `left` block ({ ref: 'user_variable' | 'source', ... })
 *   - operator (eq / neq / gt / contains / empty / ...)
 *   - right-side value (skipped for unary operators)
 *   - handle (yes / no / default / custom)
 *
 * Compiles UI state into `node.config.rules[i]` JSON via `branchOperandCompiler`.
 * Decompiles legacy `left: "flow.code"` strings and the older node-level
 * `check` path into the equivalent UI state on load.
 */
import {computed} from 'vue'
import AccordionSection from '../AccordionSection.vue'
import ConditionOperandPicker from './ConditionOperandPicker.vue'
import type {BranchOperandUiState, CompiledLeft} from '@builder/utils/branchOperandCompiler'
import {compileLeft, decompileLeft} from '@builder/utils/branchOperandCompiler'
import {useFlowVariables} from '@builder/composables/useFlowVariables'

interface RuleConfig {
    left?:     CompiledLeft | string | null
    operator?: string
    value?:    unknown
    handle?:   string
}

const props = defineProps<{
    node:   { id: string; config?: Record<string, unknown> }
    schema: Record<string, unknown>
}>()

const emit = defineEmits<{
    (e: 'update:config', patch: Record<string, unknown>): void
}>()

const OPERATORS = [
    { value: 'eq',        label: '= equals' },
    { value: 'neq',       label: '≠ not equals' },
    { value: 'gt',        label: '> greater than' },
    { value: 'gte',       label: '≥ greater or equal' },
    { value: 'lt',        label: '< less than' },
    { value: 'lte',       label: '≤ less or equal' },
    { value: 'contains',  label: 'contains' },
    { value: 'in',        label: 'in list' },
    { value: 'empty',     label: 'is empty' },
    { value: 'not_empty', label: 'is not empty' },
]

const UNARY_OPS = new Set(['empty', 'not_empty'])

const { userVars } = useFlowVariables()

const rules = computed<RuleConfig[]>(() => {
    const raw = props.node.config?.rules
    return Array.isArray(raw) ? (raw as RuleConfig[]) : []
})

const legacyCheck = computed(() => {
    const raw = props.node.config?.check
    return typeof raw === 'string' ? raw : null
})

function operandStateFor(rule: RuleConfig): BranchOperandUiState {
    // If rule has its own left, use it. Otherwise fall back to top-level legacy `check`.
    const left = rule.left ?? legacyCheck.value
    return decompileLeft(left, userVars.value)
}

function emitRules(next: RuleConfig[]) {
    // Always drop top-level legacy `check` once we start writing structured left.
    const patch: Record<string, unknown> = { rules: next }
    if (legacyCheck.value !== null) {
        patch.check = null
    }
    emit('update:config', patch)
}

function updateRule(index: number, patch: Partial<RuleConfig>) {
    const next = rules.value.map((r, i) => (i === index ? { ...r, ...patch } : r))
    emitRules(next)
}

function updateRuleLeft(index: number, state: BranchOperandUiState) {
    const compiled = compileLeft(state)
    updateRule(index, { left: compiled })
}

function addRule() {
    emitRules([
        ...rules.value,
        { left: null, operator: 'eq', value: '', handle: 'yes' },
    ])
}

function removeRule(index: number) {
    emitRules(rules.value.filter((_, i) => i !== index))
}

function ruleValueAsString(rule: RuleConfig): string {
    if (rule.value === undefined || rule.value === null) {
        return ''
    }
    return typeof rule.value === 'string' ? rule.value : String(rule.value)
}
</script>

<template>
    <div class="accordion">
        <AccordionSection title="Rules" default-open>
            <div v-for="(rule, i) in rules" :key="i" class="rule-block">
                <div class="rule-row rule-operand">
                    <span class="rule-prefix">IF</span>
                    <ConditionOperandPicker
                        :model-value="operandStateFor(rule)"
                        @update:model-value="(state: BranchOperandUiState) => updateRuleLeft(i, state)"
                    />
                </div>

                <div class="rule-row">
                    <select
                        class="field-input op-sel"
                        :value="rule.operator ?? 'eq'"
                        @change="updateRule(i, { operator: ($event.target as HTMLSelectElement).value })"
                    >
                        <option v-for="op in OPERATORS" :key="op.value" :value="op.value">
                            {{ op.label }}
                        </option>
                    </select>

                    <input
                        v-if="!UNARY_OPS.has(rule.operator ?? 'eq')"
                        type="text"
                        class="field-input"
                        placeholder="value"
                        :value="ruleValueAsString(rule)"
                        @input="updateRule(i, { value: ($event.target as HTMLInputElement).value })"
                    />
                </div>

                <div class="rule-row">
                    <span class="rule-prefix">THEN</span>
                    <select
                        class="field-input handle-sel"
                        :value="rule.handle ?? 'yes'"
                        @change="updateRule(i, { handle: ($event.target as HTMLSelectElement).value })"
                    >
                        <option value="yes">→ yes</option>
                        <option value="no">→ no</option>
                        <option value="default">→ default</option>
                    </select>

                    <button type="button" class="del-btn" @click="removeRule(i)" title="Remove rule">×</button>
                </div>
            </div>

            <button type="button" class="add-btn" @click="addRule">+ Add rule</button>
        </AccordionSection>

        <AccordionSection title="Meta">
            <div class="config-field">
                <div class="field-label">Node ID</div>
                <input
                    class="field-input"
                    style="font-family:'Victor Mono',monospace;font-size:11.5px"
                    :value="props.node.id"
                    readonly
                />
            </div>
        </AccordionSection>
    </div>
</template>

<style scoped>
.rule-block {
    padding: 8px;
    border: 1px solid var(--border);
    border-radius: 6px;
    margin-bottom: 8px;
    background: var(--surface);
    display: flex; flex-direction: column; gap: 6px;
}
.rule-row { display: flex; gap: 6px; align-items: center; }
.rule-row .field-input { flex: 1; }
.rule-operand { align-items: flex-start; }
.rule-operand > :last-child { flex: 1; }
.rule-prefix {
    font-size: 11px; font-weight: 700;
    color: var(--text-3); width: 38px;
    padding-top: 4px;
}
.op-sel { flex: 0 0 140px; }
.handle-sel { flex: 0 0 110px; }
.field-input {
    padding: 6px 9px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface-2);
    font-family: 'DM Sans', sans-serif;
    font-size: 12.5px;
    color: var(--text);
    outline: none;
}
.field-input:focus { border-color: var(--primary); background: #fff; }
.del-btn {
    background: transparent; border: none; color: var(--text-3);
    cursor: pointer; font-size: 16px; padding: 0 4px;
}
.del-btn:hover { color: var(--rose); }
.add-btn {
    width: 100%; padding: 5px;
    border: 1px dashed var(--border-2);
    border-radius: 6px; background: transparent;
    font-family: 'DM Sans', sans-serif;
    font-size: 12px; color: var(--text-3);
    cursor: pointer;
}
.add-btn:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-bg); }
.config-field { margin-bottom: 10px; }
.field-label { font-size: 12px; color: var(--text-2); margin-bottom: 4px; }
</style>
