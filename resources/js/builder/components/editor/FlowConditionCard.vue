<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import {useSelectionStore} from '@builder/store/selectionStore'
import {useBuilderStore} from '@builder/store/builderStore'
import {nodeColors} from '@builder/utils/nodeColors'
import {nodeMustBeLast} from '@builder/utils/nodeTerminal'
import NodeIcon from '@builder/components/NodeIcon.vue'


const props = defineProps({
    treeNode:     { type: Object, required: true },
    parentBranch: { type: Array,  default: () => [] },
    index:        { type: Number, default: null },
})

const selectionStore = useSelectionStore()
const builderStore   = useBuilderStore()

const handles      = computed(() => Object.keys(props.treeNode.childrenByHandle ?? {}))
const activeHandle = ref(handles.value[0] ?? 'yes')
const colors       = computed(() => nodeColors('condition'))
const isSelected   = computed(() => selectionStore.selectedNodeId === props.treeNode.node.id)
const hasError     = computed(() => builderStore.nodesWithErrors.has(props.treeNode.node.id))

watch(handles, (next) => {
    if (!next.includes(activeHandle.value)) activeHandle.value = next[0] ?? 'yes'
}, { immediate: true })

function selectCard() {
    selectionStore.select(props.treeNode.node.id)
}

function selectBranch(handle: string) {
    activeHandle.value = handle
    selectionStore.setActiveBranch([...(props.parentBranch as string[]), props.treeNode.node.id, handle])
}

function branchClass(handle: string): string {
    if (handle === 'yes') return 'branch-btn branch-yes'
    if (handle === 'no')  return 'branch-btn branch-no'
    return 'branch-btn branch-default'
}

function childCount(handle: string): number {
    return (props.treeNode.childrenByHandle?.[handle] as unknown[])?.length ?? 0
}

const canMoveUp = computed(() => {
    const node = props.treeNode.node
    if (nodeMustBeLast(node)) return false
    return builderStore.definition.edges.some((edge) => edge.to === node.id)
})

const canMoveDown = computed(() => {
    const id = props.treeNode.node.id
    const out = builderStore.definition.edges.find(
        (edge) => edge.from === id && (edge.handle ?? 'default') === 'default',
    )
    if (!out) return false
    const successor = builderStore.definition.nodes.find((n) => n.id === out.to)
    if (successor && nodeMustBeLast(successor)) return false
    return true
})
</script>

<template>
    <div class="node-card-wrap">
    <div
        :id="`node-card-${treeNode.node.id}`"
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
            <div class="node-type-icon"><NodeIcon type="condition" /></div>
            <span class="node-type-label">Condition</span>
            <div v-if="hasError" class="node-warn" title="Validation error">!</div>
            <span v-if="index != null" class="node-num">#{{ index }}</span>
            <button class="node-delete-btn" title="Delete node" @click.stop="builderStore.deleteNode(treeNode.node.id)">×</button>
        </div>

        <div class="node-card-body">
            <div v-if="treeNode.node.config?.expression" class="node-summary-row">
                <span class="node-summary-key">Expr</span>
                <span
                    class="node-summary-val"
                    style="font-family:'Victor Mono',monospace;font-size:12px"
                >{{ treeNode.node.config.expression }}</span>
            </div>
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
                    :class="[branchClass(handle), { 'active-branch': activeHandle === handle }]"
                    @click.stop="selectBranch(handle)"
                >
                    ▶ {{ handle }}
                </button>
            </div>
        </div>
    </div>

    <div class="node-side-actions" @click.stop>
        <button
            class="node-side-btn"
            title="Move up"
            :disabled="!canMoveUp"
            @click.stop="builderStore.moveNodeUp(treeNode.node.id)"
        >↑</button>
        <button
            class="node-side-btn"
            title="Move down"
            :disabled="!canMoveDown"
            @click.stop="builderStore.moveNodeDown(treeNode.node.id)"
        >↓</button>
    </div>
    </div>
</template>
