<script setup lang="ts">
import {computed} from 'vue'

const props = defineProps({
    config:  { type: Object, default: () => ({}) },
    node:    { type: Object, required: true },
})

const emit = defineEmits(['navigateBranch'])

const rulesCount = computed(() => {
    return Array.isArray(props.config.rules) ? props.config.rules.length : 0
})

const hasYes = computed(() => !!props.node.outputs?.yes?.next)
const hasNo  = computed(() => !!props.node.outputs?.no?.next)
</script>

<template>
    <div>
        <div class="summary-row">
            <span class="key">Expr</span>
            <span class="val mono">{{ config.check ?? '—' }}</span>
        </div>
        <div class="summary-row">
            <span class="key">Rules</span>
            <span class="val muted">{{ rulesCount }} rule{{ rulesCount !== 1 ? 's' : '' }}</span>
        </div>
        <div class="branches">
            <button
                class="branch-btn branch-yes"
                :disabled="!hasYes"
                type="button"
                @click.stop="emit('navigateBranch', node.id, 'yes')"
            >
                ▶ yes
            </button>
            <button
                class="branch-btn branch-no"
                :disabled="!hasNo"
                type="button"
                @click.stop="emit('navigateBranch', node.id, 'no')"
            >
                ▶ no
            </button>
        </div>
    </div>
</template>

<style scoped>
.summary-row { display:flex; align-items:baseline; gap:6px; font-size:12.5px; margin-bottom:3px; }
.key  { color:var(--text-3); min-width:56px; }
.val  { color:var(--text); font-weight:500; }
.mono { font-family:'DM Mono',monospace; font-size:12px; }
.muted { color:var(--text-2); font-weight:400; }
.branches { display:flex; gap:6px; margin-top:8px; }
.branch-btn {
    padding: 3px 10px;
    border-radius: 5px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    transition: all .15s;
    font-family: 'DM Sans', sans-serif;
}
.branch-btn:disabled { opacity: .4; cursor: default; }
.branch-yes { background: var(--sage-bg);  color: var(--sage); }
.branch-no  { background: var(--rose-bg);  color: var(--rose); }
</style>
