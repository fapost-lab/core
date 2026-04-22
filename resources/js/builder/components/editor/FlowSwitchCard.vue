<script setup>
import { computed, ref, watch } from 'vue'
import { useSelectionStore } from '@builder/store/selectionStore'
import FlowNodeCard from './FlowNodeCard.vue'
import FlowConditionCard from './FlowConditionCard.vue'

const props = defineProps({
    treeNode: { type: Object, required: true },
})

const selectionStore = useSelectionStore()
const handles = computed(() => Object.keys(props.treeNode.childrenByHandle ?? {}))
const expanded = ref({})

watch(handles, (nextHandles) => {
    for (const handle of nextHandles) {
        if (!(handle in expanded.value)) {
            expanded.value[handle] = true
        }
    }
}, { immediate: true })

function selectCard() {
    selectionStore.select(props.treeNode.node.id)
}

function toggle(handle) {
    expanded.value[handle] = !expanded.value[handle]
}
</script>

<template>
    <div
        class="w-96 rounded-lg border bg-white shadow-sm transition-all cursor-pointer"
        :class="selectionStore.selectedNodeId === treeNode.node.id ? 'border-blue-500 ring-2 ring-blue-100' : 'border-gray-200'"
        @click="selectCard"
    >
        <div class="px-4 py-3 border-b border-gray-100 text-xs font-medium text-gray-400 uppercase tracking-wide">
            Switch
        </div>
        <div class="p-3 space-y-2">
            <div v-for="handle in handles" :key="handle" class="rounded border border-gray-100">
                <button
                    class="w-full px-3 py-2 text-left text-xs text-gray-600 flex items-center justify-between"
                    type="button"
                    @click.stop="toggle(handle)"
                >
                    <span>{{ handle }} ({{ treeNode.childrenByHandle[handle]?.length ?? 0 }})</span>
                    <span>{{ expanded[handle] ? '▾' : '▸' }}</span>
                </button>
                <div v-if="expanded[handle]" class="p-2 space-y-2 border-t border-gray-100">
                    <template v-for="child in treeNode.childrenByHandle[handle] ?? []" :key="child.node.id">
                        <FlowConditionCard v-if="child.node.type === 'condition'" :tree-node="child" />
                        <FlowSwitchCard v-else-if="child.node.type === 'switch'" :tree-node="child" />
                        <FlowNodeCard v-else :tree-node="child" />
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
