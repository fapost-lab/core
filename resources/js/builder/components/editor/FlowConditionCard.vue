<script setup>
import { computed, ref, watch } from 'vue'
import { useSelectionStore } from '@builder/store/selectionStore'
import FlowNodeCard from './FlowNodeCard.vue'
import FlowSwitchCard from './FlowSwitchCard.vue'

const props = defineProps({
    treeNode: { type: Object, required: true },
    parentBranch: { type: Array, default: () => [] },
})

const selectionStore = useSelectionStore()
const handles = computed(() => Object.keys(props.treeNode.childrenByHandle ?? {}))
const activeHandle = ref(handles.value[0] ?? 'yes')

watch(handles, (nextHandles) => {
    if (!nextHandles.includes(activeHandle.value)) {
        activeHandle.value = nextHandles[0] ?? 'yes'
    }
}, { immediate: true })

function selectCard() {
    selectionStore.select(props.treeNode.node.id)
}

function selectBranch(handle) {
    activeHandle.value = handle
    selectionStore.setActiveBranch([...props.parentBranch, props.treeNode.node.id, handle])
}
</script>

<template>
    <div class="w-96">
        <div
            class="rounded-lg border bg-white shadow-sm transition-all cursor-pointer"
            :class="selectionStore.selectedNodeId === treeNode.node.id ? 'border-blue-500 ring-2 ring-blue-100' : 'border-gray-200'"
            @click="selectCard"
        >
            <div class="px-4 py-3 border-b border-gray-100 text-xs font-medium text-gray-400 uppercase tracking-wide">
                Condition
            </div>
            <div class="px-4 py-3 text-sm text-gray-600">
                {{ treeNode.node.config?.expression ?? '—' }}
            </div>
            <div class="px-4 pb-3 flex gap-2">
                <button
                    v-for="handle in handles"
                    :key="handle"
                    class="px-3 py-1 rounded text-xs font-medium transition-colors"
                    :class="activeHandle === handle
                        ? 'bg-blue-500 text-white'
                        : 'bg-gray-100 text-gray-500 hover:bg-gray-200'"
                    @click.stop="selectBranch(handle)"
                >
                    {{ handle }} ({{ treeNode.childrenByHandle[handle]?.length ?? 0 }})
                </button>
            </div>
        </div>

        <div class="ml-6 border-l-2 border-blue-100 pl-4 mt-2 space-y-2">
            <template v-for="child in treeNode.childrenByHandle[activeHandle] ?? []" :key="child.node.id">
                <FlowConditionCard
                    v-if="child.node.type === 'condition'"
                    :tree-node="child"
                    :parent-branch="[...parentBranch, treeNode.node.id, activeHandle]"
                />
                <FlowSwitchCard v-else-if="child.node.type === 'switch'" :tree-node="child" />
                <FlowNodeCard v-else :tree-node="child" />
            </template>
        </div>
    </div>
</template>
