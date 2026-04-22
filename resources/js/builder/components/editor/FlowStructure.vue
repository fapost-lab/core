<script setup>
import { useBuilderStore } from '@builder/store/builderStore'
import FlowStructureNode from './FlowStructureNode.vue'

const builderStore = useBuilderStore()
</script>

<template>
    <nav class="p-3">
        <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-2 px-2">
            Structure
        </div>
        <FlowStructureNode
            v-for="treeNode in builderStore.tree"
            :key="treeNode.node.id"
            :tree-node="treeNode"
            :depth="0"
            :parent-branch="[]"
        />
    </nav>
</template>
