<script setup lang="ts">
import {computed, ref} from 'vue'
import {useSelectionStore} from '@builder/store/selectionStore'
import {nodeColors} from '@builder/utils/nodeColors'

interface FlowNodeMeta {
    id: string
    type: string
    label?: string
    config?: Record<string, unknown>
}

interface TreeNode {
    node: FlowNodeMeta
    childrenByHandle?: Record<string, TreeNode[]>
}

const props = defineProps({
    treeNode: { type: Object as () => TreeNode, required: true },
    depth: { type: Number, default: 0 },
    parentBranch: { type: Array as () => string[], default: () => [] },
})

const selectionStore = useSelectionStore()
const expanded = ref(true)

const handles = computed((): string[] => Object.keys(props.treeNode.childrenByHandle ?? {}))

/** Only condition/switch have meaningful branches — default is just linear continuation */
const branchHandles = computed((): string[] => handles.value.filter((h: string) => h !== 'default'))
const defaultChildren = computed((): TreeNode[] => props.treeNode.childrenByHandle?.default ?? [])
const colors = computed(() => nodeColors(props.treeNode.node.type))

const nodeLabel = computed((): string => {
    const type = props.treeNode.node.type
    return props.treeNode.node.label
        ?? type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
})

function selectNode() {
    selectionStore.select(props.treeNode.node.id)
    const meaningfulBranch: string[] = []

    for (let i = 0; i < props.parentBranch.length; i += 2) {
        const nodeId = props.parentBranch[i]
        const handle = props.parentBranch[i + 1]

        if (!nodeId || !handle || handle === 'default') {
            continue
        }

        meaningfulBranch.push(nodeId, handle)
    }

    if (meaningfulBranch.length > 0) {
        selectionStore.setActiveBranch(meaningfulBranch)
        return
    }

    selectionStore.clearBranch()
}

function focusBranch(handle: string) {
    selectionStore.select(props.treeNode.node.id)
    selectionStore.setActiveBranch([...props.parentBranch, props.treeNode.node.id, handle])
}

function resolveHandleLabel(handle: string): string {
    if (props.treeNode.node.type === 'send_message') {
        const buttons = (props.treeNode.node.config?.buttons as Array<Record<string, unknown>>) ?? []
        const idx = buttons.findIndex((b) => b.id === handle)
        if (idx !== -1) {
            const lbl = buttons[idx].label
            const text = typeof lbl === 'object'
                ? String(Object.values(lbl as Record<string, unknown>)[0] ?? '')
                : String(lbl ?? '')
            return text.trim() !== '' ? text.trim() : `Button ${idx + 1}`
        }
        return `Button`
    }
    return handle
}
</script>

<template>
    <div>
        <div
            class="tree-item"
            :class="{ active: selectionStore.selectedNodeId === treeNode.node.id }"
            @click="selectNode"
        >
            <div
                class="node-icon"
                :style="{ background: colors.bg, color: colors.color }"
            >{{ colors.icon }}</div>
            <span class="truncate" style="min-width:0;flex:1">{{ nodeLabel }}</span>
            <button
                v-if="branchHandles.length > 0"
                type="button"
                style="font-size:10px;color:var(--text-3);flex-shrink:0"
                @click.stop="expanded = !expanded"
            >
                {{ expanded ? '▾' : '▸' }}
            </button>
        </div>

        <!-- Branching handles (yes/no, switch cases) — shown nested with label -->
        <div v-if="expanded && branchHandles.length > 0" class="tree-children">
            <template v-for="handle in branchHandles" :key="`${treeNode.node.id}-${handle}`">
                <div
                    class="tree-branch"
                    :class="{ active: selectionStore.activeBranch.includes(treeNode.node.id) && selectionStore.activeBranch.includes(handle) }"
                    @click="focusBranch(handle)"
                >
                    <div
                        class="branch-dot"
                        :class="handle === 'yes' ? 'yes' : handle === 'no' ? 'no' : ''"
                    />
                    {{ resolveHandleLabel(handle) }}
                </div>
                <div class="tree-children">
                    <FlowStructureNode
                        v-for="child in (treeNode.childrenByHandle ?? {})[handle] ?? []"
                        :key="child.node.id"
                        :tree-node="child"
                        :depth="depth + 1"
                        :parent-branch="[...parentBranch, treeNode.node.id, handle]"
                    />
                </div>
            </template>
        </div>

        <!-- Default handle — render children flat at same level (linear continuation) -->
        <template v-if="expanded">
            <FlowStructureNode
                v-for="child in defaultChildren"
                :key="child.node.id"
                :tree-node="child"
                :depth="depth"
                :parent-branch="[...parentBranch, treeNode.node.id, 'default']"
            />
        </template>
    </div>
</template>
