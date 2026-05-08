<script setup lang="ts">
import {computed} from 'vue'
import {useSelectionStore} from '@builder/store/selectionStore'
import {useBuilderStore} from '@builder/store/builderStore'
import {useRegistryStore} from '@builder/store/registryStore'
import type {BuilderTriggerPayload, NodeConfig} from '@builder/dto/types'
import {useConfigResize} from '@builder/composables/useConfigResize'
import {nodeColors} from '@builder/utils/nodeColors'
import SchemaConfigRenderer from './config/SchemaConfigRenderer.vue'
import SendMessageConfig from './config/overrides/SendMessageConfig.vue'
import InputConfig from './config/overrides/InputConfig.vue'
import ConditionConfig from './config/overrides/ConditionConfig.vue'
import AssignConfig from './config/overrides/AssignConfig.vue'
import TriggerConfig from './config/overrides/TriggerConfig.vue'

const OVERRIDES: Record<string, object> = {
    send_message: SendMessageConfig,
    input: InputConfig,
    condition: ConditionConfig,
    assign: AssignConfig,
}

const selectionStore = useSelectionStore()
const builderStore   = useBuilderStore()
const registryStore  = useRegistryStore()
const { width, isCollapsed, handleRef, toggle } = useConfigResize()

const selectedNode = computed(() =>
    builderStore.definition.nodes.find((n) => n.id === selectionStore.selectedNodeId) ?? null
)

const isTriggerSelected = computed(() => selectionStore.selectedNodeId === '__trigger__')

const handlerMeta = computed(() =>
    selectedNode.value
        ? registryStore.getByType(selectedNode.value.type, selectedNode.value.version)
        : null
)

const configComponent = computed(() =>
    selectedNode.value ? (OVERRIDES[selectedNode.value.type] ?? SchemaConfigRenderer) : null
)

const colors = computed(() =>
    selectedNode.value ? nodeColors(selectedNode.value.type) : null
)

const nodeTypeLabel = computed(() => {
    if (!selectedNode.value) return ''
    if (handlerMeta.value?.label) return handlerMeta.value.label
    return selectedNode.value.type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
})

function updateConfig(patch: unknown) {
    if (!selectionStore.selectedNodeId) return
    builderStore.updateNodeConfig(selectionStore.selectedNodeId, patch as NodeConfig)
}

function updateTrigger(trigger: unknown) {
    builderStore.setTrigger(trigger as BuilderTriggerPayload | null)
}

function deleteTrigger() {
    builderStore.deleteTrigger()
}
</script>

<template>
    <div
        class="panel-config"
        :style="{ width: isCollapsed ? '0' : `${width}px` }"
    >
        <!-- resize handle -->
        <div ref="handleRef" class="config-resize-handle" />

        <!-- collapse toggle -->
        <button class="config-width-toggle" :title="isCollapsed ? 'Expand' : 'Collapse'" @click="toggle">
            {{ isCollapsed ? '▶' : '◀' }}
        </button>

        <template v-if="!isCollapsed">
            <!-- empty state -->
            <div v-if="!selectedNode && !isTriggerSelected" class="config-empty">
                <div class="config-empty-icon">☰</div>
                <div class="config-empty-text">Select a node to<br>configure it</div>
            </div>

            <template v-else-if="isTriggerSelected">
                <div class="config-node-header">
                    <div
                        class="node-type-icon"
                        style="width:28px;height:28px;font-size:13px;background:var(--sky-bg);color:var(--sky)"
                    >⚡</div>
                    <div style="flex:1;min-width:0">
                        <div class="config-node-title">Trigger</div>
                        <span class="config-node-type">Flow entry configuration</span>
                    </div>
                </div>

                <div class="config-scrollable">
                    <TriggerConfig
                        :trigger="builderStore.trigger"
                        :available-events="builderStore.availableEvents"
                        @update:trigger="updateTrigger"
                        @delete:trigger="deleteTrigger"
                    />
                </div>
            </template>

            <!-- node config -->
            <template v-else-if="selectedNode">
                <div class="config-node-header">
                    <div
                        v-if="colors"
                        class="node-type-icon"
                        style="width:28px;height:28px;font-size:13px"
                        :style="{ background: colors.bg, color: colors.color }"
                    >{{ colors.icon }}</div>
                    <div style="flex:1;min-width:0">
                        <div class="config-node-title">{{ nodeTypeLabel }}</div>
                        <span class="config-node-type">
                            v{{ selectedNode.version }}
                            <template v-if="selectedNode.id"> · {{ selectedNode.id.slice(-6) }}</template>
                        </span>
                    </div>
                </div>

                <div class="config-scrollable">
                    <component
                        :is="configComponent"
                        :node="selectedNode"
                        :schema="handlerMeta?.config_schema ?? {}"
                        @update:config="updateConfig"
                    />
                </div>
            </template>
        </template>
    </div>
</template>

<style scoped>
.config-width-toggle {
    position: absolute;
    left: -14px;
    top: 50%;
    transform: translateY(-50%);
    width: 14px; height: 40px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-right: none;
    border-radius: 5px 0 0 5px;
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 9px; color: var(--text-3);
    z-index: 21;
    opacity: 0;
    transition: opacity .15s, color .15s;
}
.panel-config:hover .config-width-toggle { opacity: 1; }
.config-width-toggle:hover { color: var(--primary); }
</style>
