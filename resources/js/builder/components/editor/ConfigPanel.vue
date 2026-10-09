<script setup lang="ts">
import {computed} from 'vue'
import {useSelectionStore} from '@builder/store/selectionStore'
import {useBuilderStore} from '@builder/store/builderStore'
import {useRegistryStore} from '@builder/store/registryStore'
import type {BuilderTriggerPayload, NodeConfig} from '@builder/dto/types'
import {useConfigResize} from '@builder/composables/useConfigResize'
import {nodeColors} from '@builder/utils/nodeColors'
import NodeIcon from '@builder/components/NodeIcon.vue'
import SchemaConfigRenderer from './config/SchemaConfigRenderer.vue'
import SendMessageConfig from './config/overrides/SendMessageConfig.vue'
import InputConfig from './config/overrides/InputConfig.vue'
import ConditionConfig from './config/overrides/ConditionConfig.vue'
import AssignConfig from './config/overrides/AssignConfig.vue'
import AuthRequestConfig from './config/overrides/AuthRequestConfig.vue'
import CallConfig from './config/overrides/CallConfig.vue'
import NotifyConfig from './config/overrides/NotifyConfig.vue'
import SetTagConfig from './config/overrides/SetTagConfig.vue'
import LoopConfig from './config/overrides/LoopConfig.vue'
import LoopEndConfig from './config/overrides/LoopEndConfig.vue'
import TriggerConfig from './config/overrides/TriggerConfig.vue'
import {vendorConfigs} from '@builder/utils/vendorComponents'

// Core bespoke config panels. Resolution order is Core → vendor (Solution)
// → generic schema renderer, so Core always wins on a type collision.
const OVERRIDES: Record<string, object> = {
    send_message: SendMessageConfig,
    input: InputConfig,
    condition: ConditionConfig,
    branch: ConditionConfig,
    assign:   AssignConfig,
    call: CallConfig,
    set_tag: SetTagConfig,
    notify: NotifyConfig,
    auth_request: AuthRequestConfig,
    loop: LoopConfig,
    loop_end: LoopEndConfig,
}

const selectionStore = useSelectionStore()
const builderStore   = useBuilderStore()
const registryStore  = useRegistryStore()
const { width, handleRef } = useConfigResize()

const selectedNode = computed(() =>
    builderStore.definition.nodes.find((n) => n.id === selectionStore.selectedNodeId) ?? null
)

const isTriggerSelected = computed(() => selectionStore.selectedNodeId === '__trigger__')

// The panel is a right-side drawer: it slides in only while a node (or the
// trigger) is selected, leaving the canvas full-width the rest of the time.
const isOpen = computed(() => null !== selectedNode.value || isTriggerSelected.value)

function close() {
    selectionStore.clear()
}

const handlerMeta = computed(() =>
    selectedNode.value
        ? registryStore.getByType(selectedNode.value.type, selectedNode.value.version)
        : null
)

const configComponent = computed(() => {
    if (!selectedNode.value) return null
    const type = selectedNode.value.type
    return OVERRIDES[type] ?? vendorConfigs[type] ?? SchemaConfigRenderer
})

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
        class="panel-config panel-config--drawer"
        :class="{
            'panel-config--val-open': builderStore.validationOpen,
            'panel-config--open': isOpen,
        }"
        :style="{ width: `${width}px` }"
    >
        <!-- resize handle -->
        <div ref="handleRef" class="config-resize-handle" />

        <!-- close drawer -->
        <button class="config-width-toggle" title="Close" @click="close">▶</button>

        <template v-if="isOpen">
            <template v-if="isTriggerSelected">
                <div class="config-card-wrap">
                    <div class="config-card">
                        <div class="config-card-header">
                            <div
                                class="node-type-icon"
                                style="width:28px;height:28px;background:var(--sky-bg);color:var(--sky)"
                            >
                                <!-- Trigger isn't a node — render its own custom svg
                                     inline. Same monochrome line style as NodeIcon. -->
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"
                                     style="width:100%;height:100%;padding:14%;box-sizing:border-box">
                                    <path d="M13 3L5 13h6l-1 8 8-10h-6z" />
                                </svg>
                            </div>
                            <div style="flex:1;min-width:0">
                                <div class="config-node-title">Trigger</div>
                                <span class="config-node-type">Flow entry configuration</span>
                            </div>
                            <button class="config-close-btn" title="Close" @click="close">×</button>
                        </div>
                        <div class="config-card-body">
                            <TriggerConfig
                                :trigger="builderStore.trigger"
                                :available-events="builderStore.availableEvents"
                                @update:trigger="updateTrigger"
                                @delete:trigger="deleteTrigger"
                            />
                        </div>
                    </div>
                </div>
            </template>

            <!-- node config -->
            <template v-else-if="selectedNode">
                <div class="config-card-wrap">
                    <div class="config-card">
                        <div class="config-card-header">
                            <div
                                v-if="colors"
                                class="node-type-icon"
                                style="width:28px;height:28px"
                                :style="{ background: colors.bg, color: colors.color }"
                            ><NodeIcon :type="selectedNode.type" /></div>
                            <div style="flex:1;min-width:0">
                                <div class="config-node-title">
                                    {{ nodeTypeLabel }}
                                    <span class="config-node-meta">· V{{ selectedNode.version }}</span>
                                    <span v-if="selectedNode.id" class="config-node-meta">· {{
                                            selectedNode.id.slice(-6)
                                        }}</span>
                                </div>
                            </div>
                            <button class="config-close-btn" title="Close" @click="close">×</button>
                        </div>
                        <div class="config-card-body">
                            <component
                                :is="configComponent"
                                :node="selectedNode"
                                :schema="handlerMeta?.config_schema ?? {}"
                                @update:config="updateConfig"
                            />
                        </div>
                    </div>
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
.panel-config--open:hover .config-width-toggle { opacity: 1; }
.config-width-toggle:hover { color: var(--primary); }

/* × in the card header — primary affordance to dismiss the drawer. */
.config-close-btn {
    flex-shrink: 0;
    width: 22px; height: 22px;
    display: flex; align-items: center; justify-content: center;
    border: none;
    background: transparent;
    border-radius: 5px;
    cursor: pointer;
    font-size: 17px; line-height: 1;
    color: color-mix(in srgb, var(--band-fg) 80%, transparent);
    transition: background .12s, color .12s;
}
.config-close-btn:hover { background: color-mix(in srgb, var(--band-fg) 18%, transparent); color: var(--band-fg); }
</style>
