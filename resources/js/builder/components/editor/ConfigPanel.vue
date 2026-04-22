<script setup>
import { computed } from 'vue'
import { useSelectionStore } from '@builder/store/selectionStore'
import { useBuilderStore } from '@builder/store/builderStore'
import { useRegistryStore } from '@builder/store/registryStore'
import SchemaConfigRenderer from './config/SchemaConfigRenderer.vue'

import SendMessageConfig from './config/overrides/SendMessageConfig.vue'
import InputConfig from './config/overrides/InputConfig.vue'
import ConditionConfig from './config/overrides/ConditionConfig.vue'

const OVERRIDES = {
    send_message: SendMessageConfig,
    input: InputConfig,
    condition: ConditionConfig,
}

const selectionStore = useSelectionStore()
const builderStore = useBuilderStore()
const registryStore = useRegistryStore()

const selectedNode = computed(() =>
    builderStore.definition.nodes.find((node) => node.id === selectionStore.selectedNodeId) ?? null
)

const handlerMeta = computed(() =>
    selectedNode.value
        ? registryStore.getByType(selectedNode.value.type, selectedNode.value.version)
        : null
)

const configComponent = computed(() =>
    selectedNode.value ? (OVERRIDES[selectedNode.value.type] ?? SchemaConfigRenderer) : null
)

function updateConfig(patch) {
    if (!selectionStore.selectedNodeId) {
        return
    }

    builderStore.updateNodeConfig(selectionStore.selectedNodeId, patch)
}
</script>

<template>
    <aside class="p-4 h-full overflow-y-auto">
        <template v-if="selectedNode && handlerMeta">
            <div class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-4">
                {{ handlerMeta.label }}
                <span class="ml-1 text-gray-300">v{{ selectedNode.version }}</span>
            </div>

            <component
                :is="configComponent"
                :node="selectedNode"
                :schema="handlerMeta.config_schema ?? {}"
                @update:config="updateConfig"
            />
        </template>
        <template v-else>
            <div class="text-sm text-gray-300 text-center mt-8">
                Select a node to configure
            </div>
        </template>
    </aside>
</template>
