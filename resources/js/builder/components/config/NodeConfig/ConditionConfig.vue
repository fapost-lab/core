<script setup lang="ts">
import {computed} from 'vue'

const props = defineProps({
    modelValue: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:modelValue'])

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

type Rule = { operator: string; value: string; handle: string }
type ConditionValue = { check?: string; rules?: Rule[] }

const modelAsObj = computed((): ConditionValue => props.modelValue as ConditionValue ?? {})

const checkVal = computed({
    get: () => modelAsObj.value.check ?? '',
    set: (v) => emit('update:modelValue', { ...modelAsObj.value, check: v }),
})

const rules = computed((): Rule[] => modelAsObj.value.rules ?? [])

function updateRule(i: number, patch: Partial<Rule>) {
    const updated = rules.value.map((r: Rule, idx: number) => idx === i ? { ...r, ...patch } : r)
    emit('update:modelValue', { ...modelAsObj.value, rules: updated })
}

function addRule() {
    emit('update:modelValue', {
        ...modelAsObj.value,
        rules: [...rules.value, { operator: 'eq', value: '', handle: 'yes' }],
    })
}

function removeRule(i: number) {
    emit('update:modelValue', {
        ...modelAsObj.value,
        rules: rules.value.filter((_: Rule, idx: number) => idx !== i),
    })
}

const noValueOps = new Set(['empty', 'not_empty'])
</script>

<template>
    <!-- Check path -->
    <div class="config-section">
        <div class="config-label">Expression</div>
        <div class="config-field">
            <div class="field-label">Check <small>state path</small></div>
            <input
                type="text"
                class="field-input mono"
                placeholder="flow.status"
                :value="checkVal"
                @input="checkVal = ($event.target as HTMLInputElement).value"
            />
        </div>
    </div>

    <!-- Rules -->
    <div class="config-section">
        <div class="config-label">Rules</div>

        <div
            v-for="(rule, i) in rules"
            :key="i"
            class="rule-row"
        >
            <select
                class="field-input"
                :value="rule.operator"
                @change="updateRule(i, { operator: ($event.target as HTMLSelectElement).value })"
            >
                <option v-for="op in OPERATORS" :key="op.value" :value="op.value">
                    {{ op.label }}
                </option>
            </select>

            <input
                v-if="!noValueOps.has(rule.operator)"
                type="text"
                class="field-input"
                placeholder="value"
                :value="rule.value"
                @input="updateRule(i, { value: ($event.target as HTMLInputElement).value })"
            />

            <select
                class="field-input handle-sel"
                :value="rule.handle"
                @change="updateRule(i, { handle: ($event.target as HTMLSelectElement).value })"
            >
                <option value="yes">→ yes</option>
                <option value="no">→ no</option>
                <option value="default">→ default</option>
            </select>

            <button type="button" class="del-btn" @click="removeRule(i)">×</button>
        </div>

        <button type="button" class="add-btn" @click="addRule">+ Add rule</button>
    </div>
</template>

<style scoped>
.config-section { padding: 12px 0; border-bottom: 1px solid var(--border); }
.config-section:last-child { border-bottom: none; }
.config-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--text-3);
    margin-bottom: 7px;
}
.config-field { margin-bottom: 10px; }
.field-label {
    font-size: 12px; color: var(--text-2); margin-bottom: 4px; font-weight: 500;
}
.field-label small { font-size: 11px; color: var(--text-3); font-weight: 400; }
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
.mono { font-family: 'DM Mono', monospace; font-size: 12px; }

.rule-row { display: flex; gap: 4px; margin-bottom: 6px; align-items: center; }
.rule-row .field-input { flex: 1; }
.handle-sel { flex: 0 0 90px; }
.del-btn {
    background: transparent; border: none; color: var(--text-3);
    cursor: pointer; font-size: 16px; padding: 0 2px; transition: color .12s;
}
.del-btn:hover { color: var(--rose); }
.add-btn {
    width: 100%; padding: 5px;
    border: 1px dashed var(--border-2);
    border-radius: 6px; background: transparent;
    font-family: 'DM Sans', sans-serif;
    font-size: 12px; color: var(--text-3);
    cursor: pointer; transition: all .15s;
}
.add-btn:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-bg); }
</style>
