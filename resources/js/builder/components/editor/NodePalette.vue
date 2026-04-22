<script setup>
import { computed, ref, useTemplateRef } from 'vue'
import { onClickOutside, useEventListener } from '@vueuse/core'
import { useRegistryStore } from '@builder/store/registryStore'
import { useBuilderStore } from '@builder/store/builderStore'
import { useSelectionStore } from '@builder/store/selectionStore'

const props = defineProps({
    afterNodeId: { type: String, default: null },
    handle:      { type: String, default: 'default' },
})

const emit = defineEmits(['select', 'close'])

const registryStore = useRegistryStore()
const builderStore = useBuilderStore()
const selectionStore = useSelectionStore()
const searchQuery = ref('')
const paletteRef = useTemplateRef('paletteRef')

const filteredNodeTypes = computed(() => {
    const query = searchQuery.value.trim().toLowerCase()
    if (!query) {
        return registryStore.nodeTypes
    }

    return registryStore.nodeTypes.filter((nodeType) => (nodeType.label ?? '')
        .toLowerCase()
        .includes(query))
})

const grouped = computed(() => filteredNodeTypes.value.reduce((acc, nodeType) => {
    if (!acc[nodeType.category]) {
        acc[nodeType.category] = []
    }

    acc[nodeType.category].push(nodeType)

    return acc
}, {}))

function insert(type, version) {
    const newId = builderStore.insertNode(props.afterNodeId, props.handle, type, version)
    if (newId == null) {
        return
    }

    selectionStore.select(newId)
    emit('select')
}

onClickOutside(paletteRef, () => emit('close'))

useEventListener(document, 'keydown', (event) => {
    if (event.key === 'Escape') {
        emit('close')
    }
})
</script>

<template>
    <div
        ref="paletteRef"
        class="absolute z-50 top-full mt-1 w-64 bg-white rounded-lg border border-gray-200 shadow-lg overflow-hidden"
    >
        <div class="p-2 border-b border-gray-100">
            <input
                v-model="searchQuery"
                class="w-full text-sm px-2 py-1 rounded border border-gray-200 focus:outline-none focus:border-blue-400"
                placeholder="Search blocks..."
                autofocus
            />
        </div>

        <div class="max-h-72 overflow-y-auto py-1">
            <template v-for="(types, category) in grouped" :key="category">
                <div class="px-3 py-1 text-xs font-medium text-gray-400 uppercase tracking-wide">
                    {{ category }}
                </div>

                <button
                    v-for="nodeType in types"
                    :key="`${nodeType.type}@${nodeType.version}`"
                    class="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 transition-colors"
                    @click="insert(nodeType.type, nodeType.version)"
                >
                    {{ nodeType.label }}
                </button>
            </template>
        </div>
    </div>
</template>
