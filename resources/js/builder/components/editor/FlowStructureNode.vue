<script setup>
import { computed, ref } from 'vue'
import { useSelectionStore } from '@builder/store/selectionStore'

const props = defineProps({
    treeNode: { type: Object, required: true },
    depth: { type: Number, default: 0 },
    parentBranch: { type: Array, default: () => [] },
})

const selectionStore = useSelectionStore()
const expanded = ref(true)

const handles = computed(() => Object.keys(props.treeNode.childrenByHandle ?? {}))

function selectNode() {
    selectionStore.select(props.treeNode.node.id)
}

function focusBranch(handle) {
    selectionStore.select(props.treeNode.node.id)
    selectionStore.setActiveBranch([...props.parentBranch, props.treeNode.node.id, handle])
}
</script>

<template>
    <div>
        <div
            class="group flex items-center gap-2 rounded px-2 py-1 cursor-pointer"
            :class="selectionStore.selectedNodeId === treeNode.node.id ? 'bg-blue-50 text-blue-700' : 'text-gray-600 hover:bg-gray-50'"
            :style="{ paddingLeft: `${depth * 12 + 8}px` }"
            @click="selectNode"
        >
            <button
                v-if="handles.length > 0"
                type="button"
                class="text-xs text-gray-400 w-4"
                @click.stop="expanded = !expanded"
            >
                {{ expanded ? '▾' : '▸' }}
            </button>
            <span v-else class="w-4" />
            <span class="text-xs truncate">{{ treeNode.node.type }}</span>
            <span class="text-[10px] text-gray-400 ml-auto">{{ treeNode.node.id }}</span>
        </div>

        <div v-if="expanded && handles.length > 0" class="space-y-1">
            <div v-for="handle in handles" :key="`${treeNode.node.id}-${handle}`">
                <button
                    type="button"
                    class="ml-8 mt-1 mb-1 text-[10px] rounded px-2 py-0.5 bg-gray-100 text-gray-500 hover:bg-gray-200"
                    @click="focusBranch(handle)"
                >
                    {{ handle }}
                </button>
                <FlowStructureNode
                    v-for="child in treeNode.childrenByHandle[handle] ?? []"
                    :key="child.node.id"
                    :tree-node="child"
                    :depth="depth + 1"
                    :parent-branch="[...parentBranch, treeNode.node.id, handle]"
                />
            </div>
        </div>
    </div>
</template>
