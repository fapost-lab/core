<script setup lang="ts">
/**
 * Sequence card for the `loop` node. Renders a single "Loop body" branch
 * button (the `loop` handle) — the loop's `default` chain continues linearly
 * below the card as the after-loop continuation.
 *
 * Deleting the loop tears down the whole construct (body + auto-managed
 * loop_end), so a confirm dialog guards the action when the body has content.
 */
import {computed, ref} from 'vue'
import {useSelectionStore} from '@builder/store/selectionStore'
import {useBuilderStore} from '@builder/store/builderStore'
import {nodeColors} from '@builder/utils/nodeColors'
import {nodeMustBeLast} from '@builder/utils/nodeTerminal'
import {useMoveNode} from '@builder/composables/useMoveNode'
import {useConfirm} from '@builder/composables/useConfirm'
import {countDescendants, type TreeNode} from '@builder/utils/buildTree'
import NodeIcon from '@builder/components/NodeIcon.vue'

const props = defineProps({
    treeNode:     { type: Object, required: true },
    parentBranch: { type: Array,  default: () => [] },
    index:        { type: Number, default: null },
})

const selectionStore = useSelectionStore()
const builderStore   = useBuilderStore()
const { confirm }    = useConfirm()

const activeHandle = ref('')
const colors       = computed(() => nodeColors('loop'))
const isSelected   = computed(() => selectionStore.selectedNodeId === props.treeNode.node.id)
const hasError     = computed(() => builderStore.nodesWithErrors.has(props.treeNode.node.id))

const modeSummary = computed(() => {
    const config = (props.treeNode.node.config ?? {}) as Record<string, unknown>
    if (config.mode === 'while') return 'while condition'
    const cs = config.count_source as Record<string, unknown> | undefined
    if (cs && cs.type === 'literal') return `× ${String(cs.value ?? '?')}`
    return cs ? '× variable' : 'counted'
})

/** Total nodes in the loop body, including the auto-managed loop_end. */
const bodyNodeCount = computed(() => {
    const heads = (props.treeNode.childrenByHandle?.loop ?? []) as TreeNode[]
    return heads.reduce((sum, head) => sum + 1 + countDescendants(head), 0)
})

/** Body nodes the user actually added (everything except loop_end). */
const bodyContentCount = computed(() => Math.max(0, bodyNodeCount.value - 1))

function selectCard() {
    selectionStore.toggle(props.treeNode.node.id)
}

function selectBranch(handle: string) {
    activeHandle.value = handle
    selectionStore.setActiveBranch([...(props.parentBranch as string[]), props.treeNode.node.id, handle])
}

async function deleteLoop() {
    if (bodyContentCount.value > 0) {
        const ok = await confirm({
            title:        'Delete loop?',
            message:      `The loop and all ${bodyContentCount.value} node${bodyContentCount.value > 1 ? 's' : ''} inside its body will be removed.`,
            confirmLabel: 'Delete',
            danger:       true,
        })
        if (!ok) return
    }
    builderStore.deleteNode(props.treeNode.node.id)
}

const canMoveUp = computed(() => {
    const node = props.treeNode.node
    if (nodeMustBeLast(node)) return false
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
    return !(successor && nodeMustBeLast(successor))
})

const moveDialog = useMoveNode()

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
            <div class="node-type-icon"><NodeIcon type="loop" /></div>
            <span class="node-type-label">Loop</span>
            <div v-if="hasError" class="node-warn" title="Validation error">!</div>
            <span v-if="index != null" class="node-num">#{{ index }}</span>
            <button class="node-delete-btn" title="Delete loop and its body" @click.stop="deleteLoop">×</button>
        </div>

        <div class="node-card-body">
            <div class="node-summary-row">
                <span class="node-summary-key">Repeat</span>
                <span class="node-summary-val">{{ modeSummary }}</span>
            </div>
            <div class="condition-branches">
                <button
                    class="branch-btn branch-default"
                    :class="{ 'active-branch': activeHandle === 'loop' }"
                    title="Open the loop body — nodes that run on every iteration"
                    @click.stop="selectBranch('loop')"
                >
                    🔁 Loop body
                    <span v-if="bodyContentCount > 0" class="branch-count">{{ bodyContentCount }}</span>
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
