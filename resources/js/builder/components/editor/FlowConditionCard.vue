<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import {useSelectionStore} from '@builder/store/selectionStore'
import {useBuilderStore} from '@builder/store/builderStore'
import {nodeColors} from '@builder/utils/nodeColors'
import {nodeMustBeLast} from '@builder/utils/nodeTerminal'
import {useMoveNode} from '@builder/composables/useMoveNode'
import {useDeleteNode} from '@builder/composables/useDeleteNode'
import NodeIcon from '@builder/components/NodeIcon.vue'
import {useTranslations} from '@builder/composables/useTranslations'
import {branchRuleLabel} from '@builder/utils/branchLabel'


const props = defineProps({
    treeNode:     { type: Object, required: true },
    parentBranch: { type: Array,  default: () => [] },
    index:        { type: Number, default: null },
})

const {t} = useTranslations()
const selectionStore = useSelectionStore()
const builderStore   = useBuilderStore()

// Branches come from config.rules (like send_message reads config.buttons),
// so they appear even before they're wired.
type Rule = {handle: string; label?: string; left?: unknown; operator?: string; value?: unknown}

const rules = computed<Rule[]>(() => {
    const raw = props.treeNode.node.config?.rules
    return Array.isArray(raw) ? (raw as Rule[]) : []
})

function ruleLabel(rule: Rule, index: number): string {
    return branchRuleLabel(rule, index, t)
}

const activeHandle = ref('')
const colors       = computed(() => nodeColors('condition'))
const isSelected   = computed(() => selectionStore.selectedNodeId === props.treeNode.node.id)
const hasError     = computed(() => builderStore.nodesWithErrors.has(props.treeNode.node.id))

// Reset only if the active branch was deleted — don't auto-select on init.
watch(rules, (next) => {
    if (activeHandle.value !== '' && !next.find(r => r.handle === activeHandle.value)) {
        activeHandle.value = ''
    }
})

function selectCard() {
    selectionStore.toggle(props.treeNode.node.id)
}

function selectBranch(handle: string) {
    activeHandle.value = handle
    selectionStore.setActiveBranch([...(props.parentBranch as string[]), props.treeNode.node.id, handle])
}

function branchClass(handle: string): string {
    if (handle === 'yes') return 'branch-btn branch-yes'
    if (handle === 'no')  return 'branch-btn branch-no'
    return 'branch-btn branch-default'
}

function childCount(handle: string): number {
    return (props.treeNode.childrenByHandle?.[handle] as unknown[])?.length ?? 0
}

const canMoveUp = computed(() => {
    const node = props.treeNode.node
    if (nodeMustBeLast(node)) return false
    // Only same-chain peers (linked via `default`) are swap-eligible.
    // Non-default incoming = first child of a branch, no sibling above.
    const incoming = builderStore.definition.edges.find((edge) => edge.to === node.id)
    if (!incoming) return false
    return (incoming.handle ?? 'default') === 'default'
})

const canMoveDown = computed(() => {
    const id = props.treeNode.node.id
    const out = builderStore.definition.edges.find(
        (edge) => edge.from === id && (edge.handle ?? 'default') === 'default',
    )
    if (!out) return false
    const successor = builderStore.definition.nodes.find((n) => n.id === out.to)
    return !(successor && nodeMustBeLast(successor));

})

const moveDialog = useMoveNode()

const deleteDialog = useDeleteNode()
function openMoveDialog() {
    moveDialog.open(props.treeNode.node.id)
}
</script>

<template>
    <div class="node-card-wrap">
    <div
        :id="`node-card-${treeNode.node.id}`"
        class="node-card"
        :class="{
            selected: isSelected,
            'has-error': hasError && !isSelected,
        }"
        @click="selectCard"
    >
        <div
            class="node-card-head"
            :style="{ background: colors.bg, color: colors.color }"
        >
            <div class="node-type-icon"><NodeIcon type="condition" /></div>
            <span class="node-type-label">{{ t('nodes.branch.condition') }}</span>
            <div v-if="hasError" class="node-warn" title="Validation error">!</div>
            <span v-if="index != null" class="node-num">#{{ index }}</span>
            <button class="node-delete-btn" title="Delete node" @click.stop="deleteDialog.requestDelete(treeNode.node.id)">×</button>
        </div>

        <div class="node-card-body">
            <div v-if="rules.length > 0" class="branch-hint">{{ t('nodes.branch.wire_hint') }}</div>
            <div class="condition-branches">
                <button
                    v-for="(rule, ruleIndex) in rules"
                    :key="rule.handle"
                    class="branch-btn branch-default"
                    :class="{ 'active-branch': activeHandle === rule.handle }"
                    :title="t('nodes.branch.open_branch').replace(':name', ruleLabel(rule, ruleIndex))"
                    @click.stop="selectBranch(rule.handle)"
                >
                    {{ ruleLabel(rule, ruleIndex) }}
                    <span v-if="childCount(rule.handle) > 0" class="branch-count">{{ childCount(rule.handle) }}</span>
                    <span class="branch-chevron" aria-hidden="true">›</span>
                </button>
                <!-- Fallback branch — always last, cannot be deleted -->
                <button
                    class="branch-btn branch-fallback"
                    :class="{ 'active-branch': activeHandle === 'default' }"
                    :title="t('nodes.branch.open_otherwise')"
                    @click.stop="selectBranch('default')"
                >
                    {{ t('nodes.branch.otherwise') }}
                    <span v-if="childCount('default') > 0" class="branch-count">{{ childCount('default') }}</span>
                    <span class="branch-chevron" aria-hidden="true">›</span>
                </button>
            </div>
        </div>
    </div>

    <div class="node-side-actions" @click.stop>
        <button
            class="node-side-btn"
            title="Move up"
            :disabled="!canMoveUp"
            @click.stop="builderStore.moveNodeUp(treeNode.node.id)"
        >↑</button>
        <button
            class="node-side-btn"
            title="Move down"
            :disabled="!canMoveDown"
            @click.stop="builderStore.moveNodeDown(treeNode.node.id)"
        >↓</button>
        <button
            class="node-side-btn"
            title="Move to another branch…"
            @click.stop="openMoveDialog"
        >⇆
        </button>
    </div>
    </div>
</template>
