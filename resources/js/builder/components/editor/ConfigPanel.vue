<script setup>
import { computed } from 'vue'
import { useSelectionStore } from '@builder/store/selectionStore'
import { useBuilderStore } from '@builder/store/builderStore'
import { useRegistryStore } from '@builder/store/registryStore'

const selectionStore = useSelectionStore()
const builderStore = useBuilderStore()
const registryStore = useRegistryStore()

const selectedNode = computed(() => {
    if (!selectionStore.selectedNodeId) {
        return null
    }

    return builderStore.definition.nodes.find((node) => node.id === selectionStore.selectedNodeId) ?? null
})

const handlerMeta = computed(() => {
    if (!selectedNode.value) {
        return null
    }

    return registryStore.getByType(selectedNode.value.type, selectedNode.value.version)
})
</script>

<template>
    <aside class="p-4">
        <template v-if="selectedNode">
            <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-4">
                {{ handlerMeta?.label ?? selectedNode.type }}
            </div>
            <div class="text-xs text-gray-300">
                Config fields - 17.5
            </div>
        </template>
        <template v-else>
            <div class="text-sm text-gray-300 text-center mt-8">
                Select a node to configure
            </div>
        </template>
    </aside>
</template>
