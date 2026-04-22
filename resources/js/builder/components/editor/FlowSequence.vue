<script setup>
import { computed, ref } from 'vue'
import { useBuilderStore } from '@builder/store/builderStore'
import { useSelectionStore } from '@builder/store/selectionStore'
import { useNavigationStore } from '@builder/store/navigationStore'
import FlowNodeCard from './FlowNodeCard.vue'
import FlowInsertPoint from './FlowInsertPoint.vue'
import FlowConditionCard from './FlowConditionCard.vue'
import FlowSwitchCard from './FlowSwitchCard.vue'

const builderStore = useBuilderStore()
const selectionStore = useSelectionStore()
const navigationStore = useNavigationStore()

/** @param {Array<object>} nodes */
function flattenLinear(nodes) {
    const result = []
    for (const node of nodes ?? []) {
        result.push(node)
        const nextDefault = node.childrenByHandle?.default ?? []
        if (nextDefault.length > 0) {
            result.push(...flattenLinear(nextDefault))
        }
    }
    return result
}

/** @param {Array<object>} nodes @param {string} targetNodeId */
function findNodeInTree(nodes, targetNodeId) {
    for (const treeNode of nodes ?? []) {
        if (treeNode.node.id === targetNodeId) return treeNode
        for (const handleChildren of Object.values(treeNode.childrenByHandle ?? {})) {
            const found = findNodeInTree(handleChildren, targetNodeId)
            if (found) return found
        }
    }
    return null
}

/** @param {Array<object>} tree @param {Array<string>} activeBranch */
function resolveBranchNodes(tree, activeBranch) {
    let currentLevel = tree ?? []
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
        const nodeLabel = node?.label ?? (node?.type ?? nodeId)
        segments.push({ label: nodeLabel, key: `${nodeId}` })
        if (handle) segments.push({ label: handle.toUpperCase(), key: `${nodeId}-${handle}` })
    }
    return segments
})

const isAtRoot = computed(() => selectionStore.activeBranch.length === 0)

/** Generic trigger placeholder — real trigger config shown in a future trigger node */
const triggerLabel = computed(() => ({ label: 'Trigger', sub: 'Configured separately' }))

const hoveredSlot = ref(null)

function goToRoot() {
    selectionStore.clearBranch()
}
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
                <div class="trigger-card">
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
                <FlowSwitchCard
                    v-else-if="item.node.type === 'switch'"
                    :tree-node="item"
                    :index="index + 1"
                />
                <FlowNodeCard
                    v-else
                    :tree-node="item"
                    :index="index + 1"
                />
            </template>

            <!-- trailing slot before end card -->
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
                    :after-node-id="activeNodes[activeNodes.length - 1]?.node.id ?? null"
                    :index="activeNodes.length"
                    :visible="hoveredSlot === 'slot-end'"
                />
                <div class="seq-connector"><div class="conn-line" /></div>
            </div>

            <div class="end-card">
                <div class="end-icon">■</div>
                <span>End of flow</span>
            </div>
        </div>
    </div>
</template>
