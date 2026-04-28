<script setup lang="ts">
import {computed} from 'vue'
import {useRegistryStore} from '@builder/store/registryStore'
import {nodeColors} from '@builder/utils/nodeColors'
import ConditionConfig from './NodeConfig/ConditionConfig.vue'
import SendMessageConfig from './NodeConfig/SendMessageConfig.vue'
import DefaultConfig from './NodeConfig/DefaultConfig.vue'

const props = defineProps({
    node: { type: Object, required: true },
})

const emit = defineEmits(['update'])

const registry = useRegistryStore()
const colors   = computed(() => nodeColors(props.node.type))

const configComponent = computed(() => {
    const map: Record<string, object> = {
        condition:    ConditionConfig,
        send_message: SendMessageConfig,
    }
    return map[props.node.type] ?? DefaultConfig
})

const schema = computed(() => {
    const handler = registry.getByType(props.node.type, props.node.version ?? 1)
    return handler?.config_schema ?? {}
})

function onConfigUpdate(newConfig: unknown) {
    emit('update', props.node.id, newConfig)
}
</script>

<template>
    <div class="config-form">
        <!-- Header -->
        <div class="node-header">
            <div
                class="node-icon"
                :style="{ background: colors.bg, color: colors.color }"
            >
                {{ colors.icon }}
            </div>
            <div class="header-text">
                <div class="node-title">{{ node.label ?? node.type }}</div>
                <span class="node-meta">{{ node.type }} · v{{ node.version ?? 1 }}</span>
            </div>
        </div>

        <!-- Config form -->
        <div class="form-body">
            <component
                :is="configComponent"
                :model-value="node.config ?? {}"
                :schema="schema"
                @update:model-value="onConfigUpdate"
            />
        </div>

        <!-- Meta -->
        <div class="config-section">
            <div class="config-label">Meta</div>
            <div class="config-field">
                <div class="field-label">Node ID</div>
                <input
                    class="field-input mono"
                    :value="node.id"
                    readonly
                />
            </div>
        </div>
    </div>
</template>

<style scoped>
.config-form { display: flex; flex-direction: column; height: 100%; overflow: hidden; }

.node-header {
    padding: 10px 14px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
.node-icon {
    width: 28px;
    height: 28px;
    border-radius: 5px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    flex-shrink: 0;
}
.header-text { flex: 1; }
.node-title  { font-size: 13px; font-weight: 600; }
.node-meta   { font-size: 10.5px; color: var(--text-3); text-transform: uppercase; letter-spacing: .05em; }

.form-body {
    flex: 1;
    overflow-y: auto;
    padding: 12px 14px;
}

.config-section { padding: 12px 14px; border-top: 1px solid var(--border); }
.config-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--text-3);
    margin-bottom: 7px;
}
.config-field { margin-bottom: 0; }
.field-label { font-size: 12px; color: var(--text-2); margin-bottom: 4px; font-weight: 500; }
.field-input {
    width: 100%;
    padding: 6px 9px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface-2);
    font-family: 'DM Sans', sans-serif;
    font-size: 12.5px;
    color: var(--text);
    outline: none;
}
.mono { font-family: 'DM Mono', monospace; font-size: 11.5px; }
</style>
