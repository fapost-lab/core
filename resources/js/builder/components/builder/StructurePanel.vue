<script setup lang="ts">
import {computed} from 'vue'
import {useBuilderStore} from '@builder/store/builderStore'
import type {FlowNode} from '@builder/dto/types'
import TreeNode from './TreeNode.vue'

const builder = useBuilderStore()

const nodeMap = computed((): Record<string, FlowNode> => {
    const map: Record<string, FlowNode> = {}
    for (const n of builder.definition.nodes) {
        map[n.id] = n
    }
    return map
})

/** Find root nodes: those not referenced as next by any output of any other node. */
const rootNodes = computed(() => {
    const reachable = new Set(
        builder.definition.nodes
            .flatMap((n) => Object.values(n.outputs ?? {}).map((o) => o?.next))
            .filter(Boolean),
    )
    return builder.definition.nodes.filter((n) => !reachable.has(n.id))
})
</script>

<template>
    <div class="structure-panel">
        <div class="panel-header">Structure</div>
        <div class="panel-body">
            <div v-if="builder.definition.nodes.length === 0" class="empty">
                No nodes yet
            </div>
            <TreeNode
                v-for="node in rootNodes"
                :key="node.id"
                :node="node"
                :node-map="nodeMap"
                :depth="0"
            />
        </div>
    </div>
</template>

<style scoped>
.structure-panel {
    width: 220px;
    border-right: 1px solid var(--border);
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--surface);
}
.panel-header {
    padding: 10px 14px 9px;
    border-bottom: 1px solid var(--border);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .05em;
    text-transform: uppercase;
    color: var(--text-3);
    flex-shrink: 0;
}
.panel-body {
    flex: 1;
    overflow-y: auto;
    padding: 8px;
}
.empty {
    font-size: 12px;
    color: var(--text-3);
    text-align: center;
    padding: 24px 0;
}
</style>
