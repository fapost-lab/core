<script setup lang="ts">
import {computed} from 'vue'
import {BaseEdge, EdgeLabelRenderer, getSmoothStepPath, type EdgeProps} from '@vue-flow/core'
import GraphInsertButton from './GraphInsertButton.vue'

/**
 * An edge of the builder's graph with a "+" at its middle: a node added there goes between the edge's two nodes, on
 * the same handle (`insertNode(source, handle, …)`, the tree's "insert after"). A read-only draft draws the bare edge.
 */
const props = defineProps<EdgeProps<{ handle: string; editable: boolean; title: string }>>()

const path = computed(() =>
    getSmoothStepPath({
        sourceX: props.sourceX,
        sourceY: props.sourceY,
        sourcePosition: props.sourcePosition,
        targetX: props.targetX,
        targetY: props.targetY,
        targetPosition: props.targetPosition,
    }),
)
</script>

<template>
    <BaseEdge :id="id" :path="path[0]" :style="style" />
    <EdgeLabelRenderer v-if="data?.editable">
        <div
            class="graph-edge-insert nodrag nopan"
            :style="{ transform: `translate(-50%, -50%) translate(${path[1]}px, ${path[2]}px)` }"
        >
            <GraphInsertButton :after-node-id="source" :handle="data.handle" :title="data.title" />
        </div>
    </EdgeLabelRenderer>
</template>

<style scoped>
.graph-edge-insert {
    position: absolute;
    pointer-events: all;
    opacity: .35;
    transition: opacity .12s;
}
.graph-edge-insert:hover,
.graph-edge-insert:focus-within {
    opacity: 1;
}
</style>
