<script setup lang="ts">
import {computed} from 'vue'
import {useSelectionStore} from '@builder/store/selectionStore'
import {useBuilderStore} from '@builder/store/builderStore'
import {nodeColors} from '@builder/utils/nodeColors'
import NodeIcon from '@builder/components/NodeIcon.vue'
import {countDescendants, type TreeNode} from '@builder/utils/buildTree'

const props = defineProps({
    treeNode:     { type: Object, required: true },
    parentBranch: { type: Array,  default: () => [] },
    index:        { type: Number, default: null },
})

const selectionStore = useSelectionStore()
const builderStore   = useBuilderStore()

const node    = computed(() => props.treeNode.node)
const config  = computed(() => node.value.config ?? {})
const buttons = computed(() => Array.isArray(config.value.buttons) ? config.value.buttons : [])
const colors  = computed(() => nodeColors('send_message'))
const isSelected = computed(() => selectionStore.selectedNodeId === node.value.id)
const hasError   = computed(() => builderStore.nodesWithErrors.has(node.value.id))

function selectCard() {
    selectionStore.select(node.value.id)
}

interface KbButton {
    id: string
    label?: unknown
    [key: string]: unknown
}

function selectBranch(buttonId: string) {
    selectionStore.setActiveBranch([...(props.parentBranch as string[]), node.value.id, buttonId])
}

function btnLabel(btn: KbButton): string {
    const lbl = btn.label
    if (!lbl) return 'Button'
    if (typeof lbl === 'object') return String(Object.values(lbl as Record<string, unknown>)[0] ?? 'Button')
    return String(lbl)
}

function childCount(buttonId: string): number {
    const children: TreeNode[] = (props.treeNode.childrenByHandle?.[buttonId] ?? []) as TreeNode[]
    return children.reduce((sum: number, child: TreeNode) => sum + 1 + countDescendants(child), 0)
}

const textPreview = computed(() => {
    const t = config.value.text
    if (!t) return null
    const val = typeof t === 'object' ? Object.values(t)[0] : t
    return typeof val === 'string' ? val.slice(0, 60) : null
})
</script>

<template>
    <div
        :id="`node-card-${node.id}`"
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
            ><NodeIcon type="send_message" /></div>
            <span class="node-type-label">Send message</span>
            <div v-if="hasError" class="node-warn" title="Validation error">!</div>
            <span v-if="index != null" class="node-num">#{{ index }}</span>
            <button class="node-delete-btn" title="Delete node" @click.stop="builderStore.deleteNode(node.id)">×</button>
        </div>

        <div class="node-card-body">
            <div v-if="textPreview" class="node-summary-row">
                <span class="node-summary-key">Text</span>
                <span class="node-summary-val">{{ textPreview }}</span>
            </div>
            <div class="condition-branches">
                <button
                    v-for="btn in buttons"
                    :key="btn.id"
                    class="branch-btn branch-default"
                    @click.stop="selectBranch(btn.id)"
                >
                    {{ btnLabel(btn) }}
                    <span v-if="childCount(btn.id) > 0" class="branch-count">{{ childCount(btn.id) }}</span>
                </button>
            </div>
        </div>
    </div>
</template>
