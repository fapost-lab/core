<script setup lang="ts">
/**
 * Branch (Condition) node config — card-based branch builder.
 *
 * Each branch is a card (handle name + condition) rendered like keyboard
 * buttons in send_message. Two default branches (true / false) are seeded
 * when the node has no rules yet. The node is terminal — no "Otherwise"
 * fallback handle is shown; all paths must be explicitly connected.
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
    handle?:   string   // stable UUID used as edge identifier — never shown to user
    label?:    string   // user-visible branch name
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
    const left = rule.left ?? legacyCheck.value
    return decompileLeft(left, userVars.value)
}

function emitRules(next: RuleConfig[]) {
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
    updateRule(index, { left: compileLeft(state) })
}

function addRule() {
    emitRules([
        ...rules.value,
        // handle = stable UUID (edge identifier, never changes on rename)
        // label  = user-visible name (editable)
        { handle: crypto.randomUUID(), label: '', left: null, operator: 'eq', value: '' },
    ])
}

function removeRule(index: number) {
    emitRules(rules.value.filter((_, i) => i !== index))
}

function ruleValueAsString(rule: RuleConfig): string {
    if (rule.value === undefined || rule.value === null) return ''
    return typeof rule.value === 'string' ? rule.value : String(rule.value)
}

</script>

<template>
    <div class="accordion">
        <AccordionSection title="Branches" default-open>
            <div
                v-for="(rule, i) in rules"
                :key="i"
                class="branch-card"
            >
                <!-- Card header: handle name + delete -->
                <div class="branch-header">
                    <span class="branch-arrow">→</span>
                    <input
                        class="handle-input"
                        :value="rule.label ?? ''"
                        placeholder="Branch name…"
                        spellcheck="false"
                        @input="updateRule(i, { label: ($event.target as HTMLInputElement).value })"
                    />
                    <button type="button" class="del-btn" title="Remove branch" @click="removeRule(i)">×</button>
                </div>

                <!-- Condition body -->
                <div class="branch-body">
                    <div class="cond-row">
                        <span class="cond-label">IF</span>
                        <ConditionOperandPicker
                            class="cond-operand"
                            :model-value="operandStateFor(rule)"
                            @update:model-value="(state: BranchOperandUiState) => updateRuleLeft(i, state)"
                        />
                    </div>

                    <div class="cond-row">
                        <span class="cond-label"></span>
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
                            class="field-input val-input"
                            placeholder="value"
                            :value="ruleValueAsString(rule)"
                            @input="updateRule(i, { value: ($event.target as HTMLInputElement).value })"
                        />
                    </div>
                </div>
            </div>

            <button type="button" class="add-btn" @click="addRule">+ Add branch</button>

            <!-- Fallback: always present, cannot be deleted -->
            <div class="branch-card branch-card--fallback">
                <div class="branch-header">
                    <span class="branch-arrow" style="color:var(--text-3)">→</span>
                    <span class="fallback-label">Otherwise</span>
                    <span class="fallback-badge">fallback</span>
                </div>
                <p class="fallback-hint">
                    Taken when no branch condition matches.
                </p>
            </div>
        </AccordionSection>

        <AccordionSection title="Meta">
            <div class="config-field">
                <div class="field-label">Node ID</div>
                <input
                    class="field-input"
                    style="font-family:var(--font-mono);font-size:11.5px"
                    :value="props.node.id"
                    readonly
                />
            </div>
        </AccordionSection>
    </div>
</template>

<style scoped>
.branch-card {
    border: 1px solid var(--border);
    border-radius: 8px;
    margin-bottom: 8px;
    background: var(--surface);
    overflow: hidden;
}

.branch-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    background: var(--surface-2);
    border-bottom: 1px solid var(--border);
}

.branch-arrow {
    font-size: 13px;
    color: var(--primary);
    flex-shrink: 0;
}

.handle-input {
    flex: 1;
    border: none;
    background: transparent;
    font-family: var(--font-mono);
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text);
    outline: none;
    padding: 0;
}

.handle-input::placeholder {
    color: var(--text-3);
    font-weight: 400;
}

.del-btn {
    background: transparent;
    border: none;
    color: var(--text-3);
    cursor: pointer;
    font-size: 16px;
    padding: 0 2px;
    line-height: 1;
    flex-shrink: 0;
}

.del-btn:hover { color: var(--rose); }

.branch-body {
    padding: 8px 10px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.cond-row {
    display: flex;
    align-items: center;
    gap: 6px;
}

.cond-label {
    font-size: 11px;
    font-weight: 700;
    color: var(--text-3);
    width: 22px;
    flex-shrink: 0;
    text-align: right;
}

.cond-operand { flex: 1; }

.field-input {
    padding: 5px 8px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface-2);
    font-family: var(--font-sans);
    font-size: 12.5px;
    color: var(--text);
    outline: none;
}

.field-input:focus { border-color: var(--primary); background: var(--paper); }

.op-sel  { flex: 0 0 150px; }
.val-input { flex: 1; }

.add-btn {
    width: 100%;
    padding: 6px;
    border: 1px dashed var(--border-2);
    border-radius: 6px;
    background: transparent;
    font-family: var(--font-sans);
    font-size: 12px;
    color: var(--text-3);
    cursor: pointer;
    margin-top: 2px;
}

.add-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--primary-bg);
}

.branch-card--fallback {
    opacity: 0.75;
    border-style: dashed;
}

.fallback-label {
    flex: 1;
    font-family: var(--font-mono);
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-2);
}

.fallback-badge {
    font-size: 10px;
    font-weight: 600;
    padding: 2px 6px;
    border-radius: 4px;
    background: var(--surface-3);
    color: var(--text-3);
    letter-spacing: 0.03em;
}

.fallback-hint {
    padding: 6px 10px 8px;
    font-size: 11.5px;
    color: var(--text-3);
    margin: 0;
}

.config-field { margin-bottom: 10px; }
.field-label { font-size: 12px; color: var(--text-2); margin-bottom: 4px; }
</style>
