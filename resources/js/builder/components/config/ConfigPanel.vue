<script setup>
import { computed } from 'vue'
import { useBuilderStore }   from '@builder/store/builderStore'
import { useSelectionStore } from '@builder/store/selectionStore'
import { useConfigResize }   from '@builder/composables/useConfigResize'
import ConfigEmpty    from './ConfigEmpty.vue'
import NodeConfigForm from './NodeConfigForm.vue'

const builder   = useBuilderStore()
const selection = useSelectionStore()

const { width, isCollapsed, handleRef, toggle } = useConfigResize()

const selectedNode = computed(() => {
    if (!selection.selectedNodeId) return null
    return builder.definition.nodes.find((n) => n.id === selection.selectedNodeId) ?? null
})

function onUpdate(nodeId, config) {
    builder.updateNodeConfig(nodeId, config)
}
</script>

<template>
    <div
        class="config-panel"
        :style="{ width: isCollapsed ? '32px' : `${width}px` }"
    >
        <!-- Resize handle -->
        <div ref="handleRef" class="resize-handle">
            <div class="resize-bar"></div>
        </div>

        <!-- Collapse toggle -->
        <button class="width-toggle" type="button" @click="toggle">
            {{ isCollapsed ? '▶' : '◀' }}
        </button>

        <!-- Content -->
        <template v-if="!isCollapsed">
            <ConfigEmpty v-if="!selectedNode" />
            <NodeConfigForm
                v-else
                :node="selectedNode"
                @update="onUpdate"
            />
        </template>
    </div>
</template>

<style scoped>
.config-panel {
    border-left: 1px solid var(--border);
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    background: var(--surface);
    position: relative;
    transition: width .0s;
    min-width: 32px;
}

.resize-handle {
    position: absolute;
    left: -1px;
    top: 0; bottom: 0;
    width: 5px;
    cursor: col-resize;
    z-index: 20;
    display: flex;
    align-items: center;
    justify-content: center;
}
.resize-bar {
    width: 3px; height: 32px;
    border-radius: 2px;
    background: var(--border-2);
    opacity: 0;
    transition: opacity .15s, background .15s;
}
.resize-handle:hover .resize-bar { opacity: 1; }

.width-toggle {
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
.config-panel:hover .width-toggle { opacity: 1; }
.width-toggle:hover { color: var(--primary); }
</style>
