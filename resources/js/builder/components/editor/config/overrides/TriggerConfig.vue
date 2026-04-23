<script setup>
import { computed } from 'vue'

const props = defineProps({
    trigger: { type: Object, default: null },
    availableEvents: { type: Array, default: () => [] },
})

const emit = defineEmits(['update:trigger', 'delete:trigger'])

const triggerTypes = [
    { value: 'message', label: 'Message' },
    { value: 'schedule', label: 'Schedule' },
    { value: 'webhook', label: 'Webhook' },
    { value: 'api', label: 'API' },
    { value: 'event', label: 'Event' },
]

const currentTrigger = computed(() =>
    !props.trigger || props.trigger._delete === true
        ? createDefaultTrigger('message')
        : props.trigger
)

const hasAvailableEvents = computed(() => props.availableEvents.length > 0)

function update(patch) {
    emit('update:trigger', {
        ...currentTrigger.value,
        ...patch,
        config: {
            ...currentTrigger.value.config,
            ...(patch.config ?? {}),
        },
    })
}

function updateType(type) {
    emit('update:trigger', createDefaultTrigger(type))
}

function updateArray(key, index, value) {
    const next = [...(currentTrigger.value.config?.[key] ?? [])]
    next[index] = value
    update({ config: { [key]: next } })
}

function addArrayItem(key) {
    update({
        config: {
            [key]: [...(currentTrigger.value.config?.[key] ?? []), ''],
        },
    })
}

function removeArrayItem(key, index) {
    update({
        config: {
            [key]: (currentTrigger.value.config?.[key] ?? []).filter((_, i) => i !== index),
        },
    })
}

function removeTrigger() {
    emit('delete:trigger')
}

function createDefaultTrigger(type) {
    const base = {
        type,
        is_active: true,
        priority: 100,
    }

    return {
        ...base,
        config: defaultConfig(type),
    }
}

function defaultConfig(type) {
    switch (type) {
        case 'message':
            return { keywords: [], phrases: [] }
        case 'schedule':
            return { cron: '', timezone: 'UTC' }
        case 'webhook':
            return { method: 'POST', path: '', secret: '' }
        case 'api':
            return { route_key: '', allowed_sources: [] }
        case 'event':
            return { event_name: '' }
        default:
            return {}
    }
}
</script>

<template>
    <div class="trigger-config">
        <div class="config-section">
            <div class="config-label">General</div>

            <div class="config-field">
                <div class="field-label">Trigger type</div>
                <select
                    class="field-input"
                    :value="currentTrigger.type"
                    @change="updateType($event.target.value)"
                >
                    <option
                        v-for="option in triggerTypes"
                        :key="option.value"
                        :value="option.value"
                    >{{ option.label }}</option>
                </select>
            </div>

            <div class="config-field">
                <div class="field-label">Priority</div>
                <input
                    class="field-input"
                    type="number"
                    min="0"
                    :value="currentTrigger.priority"
                    @input="update({ priority: Number.parseInt($event.target.value || '0', 10) || 0 })"
                >
            </div>

            <label class="toggle-row">
                <input
                    type="checkbox"
                    :checked="currentTrigger.is_active"
                    @change="update({ is_active: $event.target.checked })"
                >
                <span>Trigger is active</span>
            </label>
        </div>

        <div v-if="currentTrigger.type === 'message'" class="config-section">
            <div class="config-label">Message Matching</div>

            <div class="config-field">
                <div class="field-label">Keywords</div>
                <div
                    v-for="(keyword, index) in (currentTrigger.config?.keywords ?? [])"
                    :key="`keyword-${index}`"
                    class="array-row"
                >
                    <input
                        class="field-input"
                        :value="keyword"
                        @input="updateArray('keywords', index, $event.target.value)"
                    >
                    <button type="button" class="delete-inline" @click="removeArrayItem('keywords', index)">×</button>
                </div>
                <button type="button" class="add-item-btn" @click="addArrayItem('keywords')">+ Add keyword</button>
            </div>

            <div class="config-field">
                <div class="field-label">Phrases</div>
                <div
                    v-for="(phrase, index) in (currentTrigger.config?.phrases ?? [])"
                    :key="`phrase-${index}`"
                    class="array-row"
                >
                    <input
                        class="field-input"
                        :value="phrase"
                        @input="updateArray('phrases', index, $event.target.value)"
                    >
                    <button type="button" class="delete-inline" @click="removeArrayItem('phrases', index)">×</button>
                </div>
                <button type="button" class="add-item-btn" @click="addArrayItem('phrases')">+ Add phrase</button>
            </div>
        </div>

        <div v-else-if="currentTrigger.type === 'schedule'" class="config-section">
            <div class="config-label">Schedule</div>

            <div class="config-field">
                <div class="field-label">Cron</div>
                <input
                    class="field-input"
                    :value="currentTrigger.config?.cron ?? ''"
                    placeholder="0 9 * * 1-5"
                    @input="update({ config: { cron: $event.target.value } })"
                >
            </div>

            <div class="config-field">
                <div class="field-label">Timezone</div>
                <input
                    class="field-input"
                    :value="currentTrigger.config?.timezone ?? 'UTC'"
                    @input="update({ config: { timezone: $event.target.value } })"
                >
            </div>
        </div>

        <div v-else-if="currentTrigger.type === 'webhook'" class="config-section">
            <div class="config-label">Webhook</div>

            <div class="config-field">
                <div class="field-label">Method</div>
                <select
                    class="field-input"
                    :value="currentTrigger.config?.method ?? 'POST'"
                    @change="update({ config: { method: $event.target.value } })"
                >
                    <option value="POST">POST</option>
                    <option value="GET">GET</option>
                    <option value="PUT">PUT</option>
                    <option value="PATCH">PATCH</option>
                    <option value="DELETE">DELETE</option>
                </select>
            </div>

            <div class="config-field">
                <div class="field-label">Path</div>
                <input
                    class="field-input"
                    :value="currentTrigger.config?.path ?? ''"
                    placeholder="/webhooks/hr"
                    @input="update({ config: { path: $event.target.value } })"
                >
            </div>

            <div class="config-field">
                <div class="field-label">Secret</div>
                <input
                    class="field-input"
                    :value="currentTrigger.config?.secret ?? ''"
                    @input="update({ config: { secret: $event.target.value } })"
                >
            </div>
        </div>

        <div v-else-if="currentTrigger.type === 'api'" class="config-section">
            <div class="config-label">API</div>

            <div class="config-field">
                <div class="field-label">Route key</div>
                <input
                    class="field-input"
                    :value="currentTrigger.config?.route_key ?? ''"
                    placeholder="start_onboarding"
                    @input="update({ config: { route_key: $event.target.value } })"
                >
            </div>

            <div class="config-field">
                <div class="field-label">Allowed sources</div>
                <div
                    v-for="(source, index) in (currentTrigger.config?.allowed_sources ?? [])"
                    :key="`source-${index}`"
                    class="array-row"
                >
                    <input
                        class="field-input"
                        :value="source"
                        @input="updateArray('allowed_sources', index, $event.target.value)"
                    >
                    <button type="button" class="delete-inline" @click="removeArrayItem('allowed_sources', index)">×</button>
                </div>
                <button type="button" class="add-item-btn" @click="addArrayItem('allowed_sources')">+ Add source</button>
            </div>
        </div>

        <div v-else-if="currentTrigger.type === 'event'" class="config-section">
            <div class="config-label">Event</div>

            <div class="config-field">
                <div class="field-label">Event name</div>
                <select
                    class="field-input"
                    :value="currentTrigger.config?.event_name ?? ''"
                    :disabled="!hasAvailableEvents"
                    @change="update({ config: { event_name: $event.target.value } })"
                >
                    <option value="" disabled>{{ hasAvailableEvents ? 'Select existing event' : 'No events available yet' }}</option>
                    <option
                        v-for="eventName in availableEvents"
                        :key="eventName"
                        :value="eventName"
                    >{{ eventName }}</option>
                </select>
                <div class="field-hint">
                    Events are created in the Event node. Trigger can only use events that already exist.
                </div>
            </div>
        </div>

        <div class="config-section">
            <button type="button" class="delete-trigger-btn" @click="removeTrigger">Delete trigger</button>
        </div>
    </div>
</template>

<style scoped>
.trigger-config {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.config-section {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.config-label,
.field-label {
    font-size: 12px;
    font-weight: 600;
    color: var(--text-2);
}

.config-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.field-input {
    width: 100%;
    min-height: 36px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface);
    padding: 8px 10px;
    color: var(--text);
}

.field-hint {
    font-size: 12px;
    color: var(--text-3);
}

.toggle-row {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: var(--text-2);
}

.array-row {
    display: flex;
    align-items: center;
    gap: 8px;
}

.delete-inline {
    border: none;
    background: transparent;
    color: var(--text-3);
    cursor: pointer;
    font-size: 18px;
    line-height: 1;
}

.delete-trigger-btn,
.add-item-btn {
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface);
    color: var(--text-2);
    cursor: pointer;
    padding: 8px 10px;
    text-align: left;
}

.delete-trigger-btn {
    color: var(--rose);
}
</style>
