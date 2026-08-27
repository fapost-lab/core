<script setup lang="ts">
import {useBuilderStore} from '@builder/store/builderStore'
import FlowStructureNode from './FlowStructureNode.vue'

const builderStore = useBuilderStore()
</script>

<template>
    <div class="panel panel-structure">
        <div class="structure-card">
            <div class="panel-header">Structure</div>
            <div class="panel-body">
                <FlowStructureNode
                    v-for="treeNode in builderStore.tree"
                    :key="treeNode.node.id"
                    :depth="0"
                    :parent-branch="[]"
                    :tree-node="treeNode"
                />
            </div>
        </div>
    </div>
</template>
