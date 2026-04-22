<script setup>
import { computed } from 'vue'
import { useSelectionStore } from '@builder/store/selectionStore'
import { useRegistryStore } from '@builder/store/registryStore'
import { useBuilderStore } from '@builder/store/builderStore'

const props = defineProps({
    treeNode: { type: Object, required: true },
})

const selectionStore = useSelectionStore()
const registryStore = useRegistryStore()
const builderStore = useBuilderStore()

const isSelected = computed(() =>
    selectionStore.selectedNodeId === props.treeNode.node.id
)

const handlerMeta = computed(() =>
    registryStore.getByType(props.treeNode.node.type, props.treeNode.node.version)
)

function select() {
    selectionStore.select(props.treeNode.node.id)
}

function confirmDelete() {
    if (window.confirm('Delete this node?')) {
        builderStore.deleteNode(props.treeNode.node.id)
    }
}
</script>

<template>
    <div
        class="relative group w-96 rounded-lg border bg-white shadow-sm cursor-pointer transition-all"
        :class="isSelected ? 'border-blue-500 ring-2 ring-blue-100' : 'border-gray-200 hover:border-gray-300'"
        @click="select"
    >
        <div class="absolute right-2 top-2 hidden group-hover:flex gap-1">
            <button
                class="p-1 text-gray-300 hover:text-gray-500 text-xs"
                title="Move up"
                @click.stop="builderStore.moveNodeUp(treeNode.node.id)"
            >
                ↑
            </button>
            <button
                class="p-1 text-gray-300 hover:text-gray-500 text-xs"
                title="Move down"
                @click.stop="builderStore.moveNodeDown(treeNode.node.id)"
            >
                ↓
            </button>
            <button
                class="p-1 text-gray-300 hover:text-red-400 text-xs"
                title="Delete"
                @click.stop="confirmDelete"
            >
                ✕
            </button>
        </div>

        <div class="px-4 py-3 border-b border-gray-100 flex items-center gap-2">
            <span class="text-xs font-medium text-gray-400 uppercase tracking-wide">
                {{ handlerMeta?.label ?? treeNode.node.type }}
            </span>
            <span class="text-xs text-gray-300 ml-auto">
                v{{ treeNode.node.version }}
            </span>
        </div>
        <div class="px-4 py-3 text-sm text-gray-600">
            <span v-if="treeNode.node.type === 'send_message'">
                {{ treeNode.node.config?.body?.slice(0, 60) ?? '—' }}
            </span>
            <span v-else-if="treeNode.node.type === 'input'">
                Save to: <code class="text-xs bg-gray-50 px-1 rounded">{{ treeNode.node.config?.save_to ?? '—' }}</code>
            </span>
            <span v-else-if="treeNode.node.type === 'condition'">
                {{ treeNode.node.config?.expression ?? '—' }}
            </span>
            <span v-else class="text-gray-400 italic">{{ treeNode.node.type }}</span>
        </div>
    </div>
</template>
