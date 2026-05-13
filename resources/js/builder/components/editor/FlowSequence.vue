<script setup lang="ts">
import {computed, ref} from 'vue'
import {useBuilderStore} from '@builder/store/builderStore'
import {useSelectionStore} from '@builder/store/selectionStore'
import {useNavigationStore} from '@builder/store/navigationStore'
import FlowNodeCard from './FlowNodeCard.vue'
import FlowInsertPoint from './FlowInsertPoint.vue'
import FlowConditionCard from './FlowConditionCard.vue'
import FlowSendMessageCard from './FlowSendMessageCard.vue'

interface TreeNode {
    node: { id: string; type: string; label?: string; config?: Record<string, unknown> }
    childrenByHandle?: Record<string, TreeNode[]>
}

const builderStore = useBuilderStore()
const selectionStore = useSelectionStore()
const navigationStore = useNavigationStore()

function flattenLinear(nodes: TreeNode[]): TreeNode[] {
    const result: TreeNode[] = []
    for (const node of nodes ?? []) {
        result.push(node)
        const nextDefault = node.childrenByHandle?.default ?? []
        if (nextDefault.length > 0) {
            result.push(...flattenLinear(nextDefault))
        }
    }
    return result
}

function findNodeInTree(nodes: TreeNode[], targetNodeId: string): TreeNode | null {
    for (const treeNode of nodes ?? []) {
        if (treeNode.node.id === targetNodeId) return treeNode
        for (const handleChildren of Object.values(treeNode.childrenByHandle ?? {})) {
            const found: TreeNode | null = findNodeInTree(handleChildren, targetNodeId)
            if (found) return found
        }
    }
    return null
}

function resolveBranchNodes(tree: TreeNode[], activeBranch: string[]): TreeNode[] {
    let currentLevel: TreeNode[] = tree ?? []
    for (let i = 0; i < activeBranch.length; i += 2) {
        const nodeId = activeBranch[i]
        const handle = activeBranch[i + 1]
        if (!nodeId || !handle) break
        const parent = findNodeInTree(currentLevel, nodeId)
        if (!parent) break
        currentLevel = parent.childrenByHandle?.[handle] ?? []
    }
    return flattenLinear(currentLevel)
}

const activeNodes = computed(() => resolveBranchNodes(
    builderStore.tree,
    selectionStore.activeBranch,
))

/** Breadcrumb segments based on activeBranch + node labels */
const breadcrumbs = computed(() => {
    const segments = [{ label: 'Main flow', key: 'root' }]
    const branch = selectionStore.activeBranch
    for (let i = 0; i < branch.length; i += 2) {
        const nodeId = branch[i]
        const handle = branch[i + 1]
        if (!nodeId) break
        const node = builderStore.definition.nodes.find((n) => n.id === nodeId)
        const nodeLabel = node?.label
            ?? node?.type?.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
            ?? nodeId
        segments.push({ label: nodeLabel, key: `${nodeId}` })
        if (handle) {
            let handleLabel = handle
            if (node?.type === 'send_message' && Array.isArray(node?.config?.buttons)) {
                const buttons = node.config.buttons as Array<Record<string, unknown>>
                const idx = buttons.findIndex((b) => b.id === handle)
                if (idx !== -1) {
                    const lbl = buttons[idx].label
                    const text = typeof lbl === 'object'
                        ? String(Object.values(lbl as Record<string, unknown>)[0] ?? '')
                        : String(lbl ?? '')
                    handleLabel = text.trim() !== '' ? text.trim() : `Button ${idx + 1}`
                } else {
                    handleLabel = 'Button'
                }
            }
            segments.push({ label: handleLabel, key: `${nodeId}-${handle}` })
        }
    }
    return segments
})

const isAtRoot = computed(() => selectionStore.activeBranch.length === 0)

const triggerLabel = computed(() => {
    if (!builderStore.trigger || builderStore.trigger._delete === true) {
        return {
            label: 'No trigger configured',
            sub: 'Click to add a trigger',
        }
    }

    const type = builderStore.trigger.type.replace(/_/g, ' ')
    const label = `${type.charAt(0).toUpperCase()}${type.slice(1)} trigger`

    if (builderStore.trigger.type === 'message') {
        const keywords = (builderStore.trigger.config?.keywords as unknown[]) ?? []
        const phrases = (builderStore.trigger.config?.phrases as unknown[]) ?? []
        const total = keywords.length + phrases.length

        return {
            label,
            sub: total > 0 ? `${total} message matcher${total > 1 ? 's' : ''}` : 'No phrases or keywords yet',
        }
    }

    if (builderStore.trigger.type === 'event') {
        return {
            label,
            sub: builderStore.trigger.config?.event_name ?? 'No event selected',
        }
    }

    return {
        label,
        sub: builderStore.trigger.is_active ? 'Active' : 'Inactive',
    }
})

const hoveredSlot = ref<string | null>(null)

function goToRoot() {
    selectionStore.clearBranch()
}

/**
 * True when the last visible node is terminal — no further nodes may be added.
 * Two cases for send_message:
 *   - inline keyboard with buttons: branches are the exit path
 *   - reply keyboard: pressing a reply button triggers a separate flow via
 *     keyword/trigger matching, so this flow ends here entirely
 */
const lastNodeIsTerminal = computed(() => {
    const last = activeNodes.value[activeNodes.value.length - 1]
    if (!last) return false
    const { type, config } = last.node
    // Real `end` node already terminates the chain — no decorative tail card.
    if (type === 'end') return true
    if (type !== 'send_message' || config?.content_type !== 'text_with_keyboard') return false
    const mode = config?.keyboard_mode ?? 'inline'
    if (mode === 'reply') return true
    return ((config?.buttons as unknown[] | undefined)?.length ?? 0) > 0
})

/**
 * For the trailing insert point: when inside a branch and no nodes exist yet,
 * use the branch parent node + handle so insertNode creates the edge correctly
 * instead of trying to insert at the flow root.
 */
const trailingInsertContext = computed(() => {
    const last = activeNodes.value[activeNodes.value.length - 1]
    if (last) {
        return { afterNodeId: last.node.id, handle: 'default' }
    }
    const branch = selectionStore.activeBranch
    if (branch.length >= 2) {
        return {
            afterNodeId: branch[branch.length - 2],
            handle: branch[branch.length - 1],
        }
    }
    return { afterNodeId: null, handle: 'default' }
})
</script>

<template>
    <div class="panel-sequence">
        <div class="sequence-wrap">
            <!-- Breadcrumb -->
            <div class="breadcrumb">
                <template v-for="(seg, i) in breadcrumbs" :key="seg.key">
                    <span v-if="i > 0" class="breadcrumb-sep">/</span>
                    <a
                        v-if="i < breadcrumbs.length - 1"
                        @click="i === 0 ? goToRoot() : null"
                    >{{ seg.label }}</a>
                    <span v-else class="breadcrumb-current">{{ seg.label }}</span>
                </template>
            </div>

            <!-- Trigger card (only at root) -->
            <template v-if="isAtRoot">
                <div class="trigger-card" @click="selectionStore.selectTrigger()">
                    <div class="trigger-icon">⚡</div>
                    <div>
                        <div class="trigger-label">{{ triggerLabel.label }}</div>
                        <div v-if="triggerLabel.sub" class="trigger-sub">{{ triggerLabel.sub }}</div>
                    </div>
                </div>
            </template>

            <!-- Nodes -->
            <template v-for="(item, index) in activeNodes" :key="item.node.id">
                <!-- slot between nodes: hover group reveals insert button -->
                <div
                    class="seq-slot"
                    @mouseenter="hoveredSlot = `slot-${index}`"
                    @mouseleave="hoveredSlot = null"
                >
                    <div class="seq-connector">
                        <div class="conn-line" />
                        <div class="conn-dot" />
                        <div class="conn-line" />
                    </div>
                    <FlowInsertPoint
                        :after-node-id="activeNodes[index - 1]?.node.id ?? null"
                        :index="index"
                        :visible="hoveredSlot === `slot-${index}`"
                    />
                    <div class="seq-connector"><div class="conn-line" /></div>
                </div>

                <FlowConditionCard
                    v-if="item.node.type === 'condition'"
                    :tree-node="item"
                    :parent-branch="selectionStore.activeBranch"
                    :index="index + 1"
                />
                <FlowSendMessageCard
                    v-else-if="
                        item.node.type === 'send_message'
                            && item.node.config?.content_type === 'text_with_keyboard'
                            && (item.node.config?.keyboard_mode ?? 'inline') !== 'reply'
                            && ((item.node.config?.buttons as unknown[] | undefined)?.length ?? 0) > 0
                    "
                    :tree-node="item"
                    :parent-branch="selectionStore.activeBranch"
                    :index="index + 1"
                />
                <FlowNodeCard
                    v-else
                    :tree-node="item"
                    :index="index + 1"
                />
            </template>

            <!-- trailing slot + end card — hidden when last node is inline keyboard (buttons are the exit) -->
            <template v-if="!lastNodeIsTerminal">
                <div
                    class="seq-slot"
                    @mouseenter="hoveredSlot = 'slot-end'"
                    @mouseleave="hoveredSlot = null"
                >
                    <div class="seq-connector">
                        <div class="conn-line" />
                        <div class="conn-dot" />
                        <div class="conn-line" />
                    </div>
                    <FlowInsertPoint
                        :after-node-id="trailingInsertContext.afterNodeId"
                        :handle="trailingInsertContext.handle"
                        :index="activeNodes.length"
                        :visible="hoveredSlot === 'slot-end'"
                    />
                    <div class="seq-connector"><div class="conn-line" /></div>
                </div>
            </template>
        </div>
    </div>
</template>
