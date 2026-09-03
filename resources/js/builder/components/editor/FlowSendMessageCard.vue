<script setup lang="ts">
import {computed} from 'vue'
import {useSelectionStore} from '@builder/store/selectionStore'
import {useBuilderStore} from '@builder/store/builderStore'
import {nodeColors} from '@builder/utils/nodeColors'
import {nodeMustBeLast} from '@builder/utils/nodeTerminal'
import {useMoveNode} from '@builder/composables/useMoveNode'
import {useDeleteNode} from '@builder/composables/useDeleteNode'
import NodeIcon from '@builder/components/NodeIcon.vue'
import {countDescendants, type TreeNode} from '@builder/utils/buildTree'

const props = defineProps({
    treeNode:     { type: Object, required: true },
    parentBranch: { type: Array,  default: () => [] },
    index:        { type: Number, default: null },
})

const selectionStore = useSelectionStore()
const builderStore   = useBuilderStore()

const node    = computed(() => props.treeNode.node)
const config  = computed(() => node.value.config ?? {})
const buttons = computed(() => Array.isArray(config.value.buttons) ? config.value.buttons : [])
const colors  = computed(() => nodeColors('send_message'))
const isSelected = computed(() => selectionStore.selectedNodeId === node.value.id)
const hasError   = computed(() => builderStore.nodesWithErrors.has(node.value.id))

function selectCard() {
    selectionStore.toggle(node.value.id)
}

interface KbButton {
    id: string
    label?: unknown
    [key: string]: unknown
}

function selectBranch(buttonId: string) {
    selectionStore.setActiveBranch([...(props.parentBranch as string[]), node.value.id, buttonId])
}

function btnLabel(btn: KbButton): string {
    const lbl = btn.label
    if (!lbl) return 'Button'
    if (typeof lbl === 'object') return String(Object.values(lbl as Record<string, unknown>)[0] ?? 'Button')
    return String(lbl)
}

function childCount(buttonId: string): number {
    const children: TreeNode[] = (props.treeNode.childrenByHandle?.[buttonId] ?? []) as TreeNode[]
    return children.reduce((sum: number, child: TreeNode) => sum + 1 + countDescendants(child), 0)
}

const canMoveUp = computed(() => {
    if (nodeMustBeLast(node.value)) return false
    // Only same-chain peers (linked via `default`) are swap-eligible.
    // Non-default incoming = first child of a branch, no sibling above.
    const incoming = builderStore.definition.edges.find((edge) => edge.to === node.value.id)
    if (!incoming) return false
    return (incoming.handle ?? 'default') === 'default'
})

const canMoveDown = computed(() => {
    const id = node.value.id
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
    moveDialog.open(node.value.id)
}

const textPreview = computed(() => {
    const t = config.value.text
    if (!t) return null
    const val = typeof t === 'object' ? Object.values(t)[0] : t
    return typeof val === 'string' ? val.slice(0, 60) : null
})
</script>

<template>
    <div class="node-card-wrap">
    <div
        :id="`node-card-${node.id}`"
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
            <div class="node-type-icon"><NodeIcon type="send_message" /></div>
            <span class="node-type-label">Send message</span>
            <div v-if="hasError" class="node-warn" title="Validation error">!</div>
            <span v-if="index != null" class="node-num">#{{ index }}</span>
            <button class="node-delete-btn" title="Delete node" @click.stop="deleteDialog.requestDelete(node.id)">×</button>
        </div>

        <div class="node-card-body">
            <div v-if="textPreview" class="node-summary-row">
                <span class="node-summary-key">Text</span>
                <span class="node-summary-val">{{ textPreview }}</span>
            </div>
            <div v-if="buttons.length > 0" class="branch-hint">
                Click a button to wire its branch →
            </div>
            <div class="condition-branches">
                <button
                    v-for="btn in buttons"
                    :key="btn.id"
                    class="branch-btn branch-default"
                    :title="`Open ${btnLabel(btn)} branch`"
                    @click.stop="selectBranch(btn.id)"
                >
                    {{ btnLabel(btn) }}
                    <span v-if="childCount(btn.id) > 0" class="branch-count">{{ childCount(btn.id) }}</span>
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
            @click.stop="builderStore.moveNodeUp(node.id)"
        >↑</button>
        <button
            class="node-side-btn"
            title="Move down"
            :disabled="!canMoveDown"
            @click.stop="builderStore.moveNodeDown(node.id)"
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
