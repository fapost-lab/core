<script setup lang="ts">
/**
 * Subflow node config — lets the author pick a target flow from the assistant's
 * flow list and configure an optional timeout. Replaces the generic text-field
 * schema renderer so that flow_id is a searchable dropdown, not a raw UUID.
 */
import {computed} from 'vue'
import AccordionSection from '../AccordionSection.vue'
import {useBuilderStore} from '@builder/store/builderStore'

const builderStore = useBuilderStore()

const props = defineProps({
    node:   { type: Object, required: true },
    schema: { type: Object, required: true },
})

const emit = defineEmits(['update:config'])

const config = computed(() => (props.node.config ?? {}) as Record<string, unknown>)

const selectedFlowId = computed(() => (config.value.flow_id as string | undefined) ?? '')
const timeout        = computed(() => (config.value.timeout as string | undefined) ?? 'PT24H')

const currentFlow = computed(() =>
    builderStore.availableFlows.find(f => f.id === selectedFlowId.value) ?? null
)

// Exclude the current flow from the picker — a flow cannot call itself.
const selectableFlows = computed(() =>
    builderStore.availableFlows.filter(f => f.id !== builderStore.flowId)
)

const TIMEOUT_PRESETS = [
    { value: 'PT1H',  label: '1 hour' },
    { value: 'PT6H',  label: '6 hours' },
    { value: 'PT12H', label: '12 hours' },
    { value: 'PT24H', label: '24 hours' },
    { value: 'PT48H', label: '48 hours' },
    { value: 'P7D',   label: '7 days' },
]

function update(key: string, value: unknown) {
    emit('update:config', { [key]: value })
}
</script>

<template>
    <div class="accordion">
        <AccordionSection title="Target flow" default-open>
            <div class="config-field">
                <div class="field-label">Flow</div>
                <select
                    class="field-input"
                    :value="selectedFlowId"
                    @change="update('flow_id', ($event.target as HTMLSelectElement).value)"
                >
                    <option value="">— select a flow —</option>
                    <option
                        v-for="flow in selectableFlows"
                        :key="flow.id"
                        :value="flow.id"
                    >{{ flow.name }}</option>
                </select>
                <p v-if="currentFlow" class="field-help">
                    Calls <strong>{{ currentFlow.name }}</strong> as a child session. Resumes here
                    via <em>success</em>, <em>cancelled</em>, or <em>failed</em> once the child ends.
                </p>
                <p v-else class="field-help">
                    The selected flow runs as a child session. Parent session is paused until the child finishes.
                </p>
            </div>
        </AccordionSection>

        <AccordionSection title="Timeout">
            <div class="config-field">
                <div class="field-label">Max wait time</div>
                <select
                    class="field-input"
                    :value="timeout"
                    @change="update('timeout', ($event.target as HTMLSelectElement).value)"
                >
                    <option v-for="p in TIMEOUT_PRESETS" :key="p.value" :value="p.value">
                        {{ p.label }}
                    </option>
                </select>
                <p class="field-help">
                    If the child flow doesn't complete within this time, the parent routes to <em>failed</em>.
                </p>
            </div>
        </AccordionSection>

        <AccordionSection title="Outputs">
            <div class="outputs-list">
                <div class="output-row output-success">
                    <span class="output-handle">success</span>
                    <span class="output-desc">Child flow ended at an <em>end</em> node with status <em>success</em></span>
                </div>
                <div class="output-row output-cancelled">
                    <span class="output-handle">cancelled</span>
                    <span class="output-desc">Child ended with status <em>cancelled</em></span>
                </div>
                <div class="output-row output-failed">
                    <span class="output-handle">failed</span>
                    <span class="output-desc">Child ended with status <em>failed</em>, timed out, or target flow is inactive</span>
                </div>
            </div>
        </AccordionSection>

        <AccordionSection title="Meta">
            <div class="config-field">
                <div class="field-label">Node ID</div>
                <input
                    class="field-input"
                    style="font-family:'Victor Mono',monospace;font-size:11.5px"
                    :value="props.node.id"
                    readonly
                />
            </div>
        </AccordionSection>
    </div>
</template>

<style scoped>
.config-field { margin-bottom: 10px; }

.field-label {
    font-size: 12px;
    color: var(--text-2);
    margin-bottom: 4px;
}

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

.field-input:focus { border-color: var(--primary); background: #fff; }

.field-help {
    margin: 5px 0 0;
    font-size: 11.5px;
    color: var(--text-3);
    line-height: 1.5;
}

.outputs-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.output-row {
    display: flex;
    align-items: baseline;
    gap: 10px;
    padding: 6px 9px;
    border-radius: 6px;
    font-size: 12px;
}

.output-handle {
    font-family: 'Victor Mono', monospace;
    font-size: 11.5px;
    font-weight: 600;
    min-width: 70px;
    flex-shrink: 0;
}

.output-desc { color: var(--text-2); line-height: 1.4; }

.output-success  { background: var(--sage-bg);  color: var(--sage); }
.output-cancelled { background: var(--amber-bg, #fff8e1); color: var(--amber, #b8860b); }
.output-failed   { background: var(--rose-bg);  color: var(--rose); }
.output-cancelled .output-desc,
.output-success  .output-desc,
.output-failed   .output-desc { color: inherit; opacity: 0.8; }
</style>
