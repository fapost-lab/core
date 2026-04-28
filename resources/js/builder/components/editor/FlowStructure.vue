<script setup lang="ts">
import {useBuilderStore} from '@builder/store/builderStore'
import FlowStructureNode from './FlowStructureNode.vue'

const builderStore = useBuilderStore()
</script>

<template>
    <div class="panel panel-structure">
        <div class="panel-header">Structure</div>
        <div class="panel-body">
            <FlowStructureNode
                v-for="treeNode in builderStore.tree"
                :key="treeNode.node.id"
                :tree-node="treeNode"
                :depth="0"
                :parent-branch="[]"
            />
        </div>
    </div>
</template>
