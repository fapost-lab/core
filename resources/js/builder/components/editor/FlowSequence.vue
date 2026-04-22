<script setup>
import { computed } from 'vue'
import { useBuilderStore } from '@builder/store/builderStore'
import { useSelectionStore } from '@builder/store/selectionStore'
import FlowNodeCard from './FlowNodeCard.vue'
import FlowInsertPoint from './FlowInsertPoint.vue'
import FlowConditionCard from './FlowConditionCard.vue'
import FlowSwitchCard from './FlowSwitchCard.vue'

const builderStore = useBuilderStore()
const selectionStore = useSelectionStore()

/**
 * @param {Array<object>} nodes
 * @returns {Array<object>}
 */
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

/**
 * @param {Array<object>} nodes
 * @param {string} targetNodeId
 * @returns {object|null}
 */
function findNodeInTree(nodes, targetNodeId) {
    for (const treeNode of nodes ?? []) {
        if (treeNode.node.id === targetNodeId) {
            return treeNode
        }

        for (const handleChildren of Object.values(treeNode.childrenByHandle ?? {})) {
            const found = findNodeInTree(handleChildren, targetNodeId)
            if (found) {
                return found
            }
        }
    }

    return null
}

/**
 * @param {Array<object>} tree
 * @param {Array<string>} activeBranch
 * @returns {Array<object>}
 */
function resolveBranchNodes(tree, activeBranch) {
    let currentLevel = tree ?? []

    for (let i = 0; i < activeBranch.length; i += 2) {
        const nodeId = activeBranch[i]
        const handle = activeBranch[i + 1]
        if (!nodeId || !handle) {
            break
        }

        const parent = findNodeInTree(currentLevel, nodeId)
        if (!parent) {
            break
        }

        currentLevel = parent.childrenByHandle?.[handle] ?? []
    }

    return flattenLinear(currentLevel)
}

const activeNodes = computed(() => resolveBranchNodes(
    builderStore.tree,
    selectionStore.activeBranch,
))
</script>

<template>
    <div class="flex flex-col items-center gap-0">
        <template v-for="(item, index) in activeNodes" :key="item.node.id">
            <FlowInsertPoint :after-node-id="activeNodes[index - 1]?.node.id ?? null" :index="index" />
            <FlowConditionCard
                v-if="item.node.type === 'condition'"
                :tree-node="item"
                :parent-branch="selectionStore.activeBranch"
            />
            <FlowSwitchCard v-else-if="item.node.type === 'switch'" :tree-node="item" />
            <FlowNodeCard v-else :tree-node="item" />
        </template>
        <FlowInsertPoint :after-node-id="activeNodes[activeNodes.length - 1]?.node.id ?? null" :index="activeNodes.length" />
    </div>
</template>
