<script setup lang="ts">
import {computed, inject, ref} from 'vue'
import {useSelectionStore} from '@builder/store/selectionStore'
import type {FlowNode} from '@builder/dto/types'
import {nodeColors} from '@builder/utils/nodeColors'

const props = defineProps({
    node:     { type: Object as () => FlowNode, required: true },
    nodeMap:  { type: Object as () => Record<string, FlowNode>, required: true },
    depth:    { type: Number,  default: 0 },
    isActive: { type: Boolean, default: false },
})

const selection    = useSelectionStore()
const scrollToNode = inject<((id: string) => void) | null>('scrollToNode', null)
const expanded     = ref(true)

const colors = computed(() => nodeColors(props.node.type))

/** Direct children accessible from this node's outputs. */
const children = computed(() => {
    const outputs = props.node.outputs ?? {}
    return Object.entries(outputs)
        .map(([key, out]) => ({ key, node: out?.next ? (props.nodeMap[out.next] ?? null) : null }))
        .filter((e): e is { key: string; node: FlowNode } => e.node !== null)
})

function selectNode() {
    selection.select(props.node.id)
    if (scrollToNode) scrollToNode(props.node.id)
}
</script>

<template>
    <div>
        <!-- Node row -->
        <div
            class="tree-item"
            :class="{ active: selection.selectedNodeId === node.id }"
            :style="{ paddingLeft: `${8 + depth * 12}px` }"
            @click="selectNode"
        >
            <div
                class="node-icon"
                :style="{ background: colors.bg, color: colors.color }"
            >
                {{ colors.icon }}
            </div>
            <span class="node-label">{{ depth + 1 }}. {{ node.label ?? node.type }}</span>

            <button
                v-if="children.length > 0"
                class="expand-btn"
                type="button"
                @click.stop="expanded = !expanded"
            >
                {{ expanded ? '▾' : '▸' }}
            </button>
        </div>

        <!-- Children (branches) -->
        <div v-if="expanded && children.length > 0" class="tree-children">
            <template v-for="child in children" :key="child.key">
                <!-- Branch label for condition outputs -->
                <div
                    v-if="children.length > 1"
                    class="tree-branch"
                >
                    <div
                        class="branch-dot"
                        :class="{ yes: child.key === 'yes', no: child.key === 'no' }"
                    ></div>
                    {{ child.key }}
                </div>

                <TreeNode
                    :node="child.node"
                    :node-map="nodeMap"
                    :depth="depth + 1"
                />
            </template>
        </div>
    </div>
</template>

<style scoped>
.tree-item {
    display: flex;
    align-items: center;
    gap: 6px;
    padding-top: 5px;
    padding-bottom: 5px;
    padding-right: 8px;
    border-radius: 6px;
    cursor: pointer;
    color: var(--text-2);
    font-size: 12.5px;
    transition: all .12s;
    user-select: none;
}
.tree-item:hover { background: var(--surface-2); color: var(--text); }
.tree-item.active { background: var(--primary-bg); color: var(--primary); font-weight: 500; }

.node-icon {
    width: 18px;
    height: 18px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    flex-shrink: 0;
}
.node-label { flex: 1; }
.expand-btn {
    background: transparent;
    border: none;
    color: var(--text-3);
    cursor: pointer;
    font-size: 10px;
    padding: 0 2px;
}

.tree-children {
    margin-left: 9px;
    padding-left: 10px;
    border-left: 1.5px solid var(--border);
}
.tree-branch {
    padding: 3px 8px 3px 10px;
    font-size: 12px;
    color: var(--text-3);
    display: flex;
    align-items: center;
    gap: 5px;
}
.branch-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--border-2);
    flex-shrink: 0;
}
.branch-dot.yes { background: var(--sage); }
.branch-dot.no  { background: var(--rose); }
</style>
