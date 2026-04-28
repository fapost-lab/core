<script setup lang="ts">
import {computed, ref, watch} from 'vue'

import {useSelectionStore} from '@builder/store/selectionStore'
import {useBuilderStore} from '@builder/store/builderStore'
import {nodeColors} from '@builder/utils/nodeColors'
import {countDescendants, type TreeNode} from '@builder/utils/buildTree'

const props = defineProps({
    treeNode:     { type: Object, required: true },
    parentBranch: { type: Array,  default: () => [] },
    index:        { type: Number, default: null },
})

const selectionStore = useSelectionStore()
const builderStore   = useBuilderStore()

const handles      = computed(() => Object.keys(props.treeNode.childrenByHandle ?? {}))
const activeHandle = ref(handles.value[0] ?? null)
const colors       = computed(() => nodeColors('switch') ?? nodeColors('_default'))
const isSelected   = computed(() => selectionStore.selectedNodeId === props.treeNode.node.id)
const hasError     = computed(() => builderStore.nodesWithErrors.has(props.treeNode.node.id))

watch(handles, (next) => {
    if (!next.includes(activeHandle.value)) activeHandle.value = next[0] ?? null
}, { immediate: true })

function selectCard() {
    selectionStore.select(props.treeNode.node.id)
}

function selectBranch(handle: string) {
    activeHandle.value = handle
    selectionStore.setActiveBranch([...(props.parentBranch as string[]), props.treeNode.node.id, handle])
}

function childCount(handle: string): number {
    const children: TreeNode[] = (props.treeNode.childrenByHandle?.[handle] ?? []) as TreeNode[]
    return children.reduce((sum: number, child: TreeNode) => sum + 1 + countDescendants(child), 0)
}
</script>

<template>
    <div
        :id="`node-card-${treeNode.node.id}`"
        class="node-card"
        :class="{
            selected: isSelected,
            'has-error': hasError && !isSelected,
        }"
        @click="selectCard"
    >
        <div class="node-card-head">
            <div
                class="node-type-icon"
                :style="{ background: colors.bg, color: colors.color }"
            >{{ colors.icon }}</div>
            <span class="node-type-label">Switch</span>
            <div v-if="hasError" class="node-warn" title="Validation error">!</div>
            <span v-if="index != null" class="node-num">#{{ index }}</span>
            <button class="node-delete-btn" title="Delete node" @click.stop="builderStore.deleteNode(treeNode.node.id)">×</button>
        </div>

        <div class="node-card-body">
            <div
                v-for="handle in handles"
                :key="handle"
                class="node-summary-row"
            >
                <span class="node-summary-key" style="text-transform:uppercase">{{ handle }}</span>
                <span class="node-summary-val muted">{{ childCount(handle) }} nodes</span>
            </div>

            <div class="condition-branches">
                <button
                    v-for="handle in handles"
                    :key="handle"
                    class="branch-btn branch-default"
                    :class="{ 'active-branch': activeHandle === handle }"
                    @click.stop="selectBranch(handle)"
                >
                    {{ handle }}
                </button>
            </div>
        </div>
    </div>
</template>
