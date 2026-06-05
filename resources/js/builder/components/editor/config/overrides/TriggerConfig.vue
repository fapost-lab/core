<script setup lang="ts">
import {computed, ref} from 'vue'
import AccordionSection from '../AccordionSection.vue'

const props = defineProps({
    trigger: { type: Object as () => Record<string, unknown> | null, default: null },
    availableEvents: { type: Array as () => string[], default: () => [] },
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

const triggerCfg = computed((): Record<string, unknown> =>
    (currentTrigger.value.config as Record<string, unknown>) ?? {}
)

/**
 * Event picker: searchable combobox over tenant-wide events (all assistants),
 * with free-text entry — an event name can be referenced before it is published.
 */
const eventDropdownOpen = ref(false)

const currentEventName = computed<string>(() => (triggerCfg.value.event_name as string) ?? '')

const filteredEvents = computed<string[]>(() => {
    const query = currentEventName.value.trim().toLowerCase()
    if (query === '') {
        return props.availableEvents
    }
    return props.availableEvents.filter((name) => name.toLowerCase().includes(query))
})

function setEventName(name: string): void {
    update({ config: { event_name: name } })
}

function selectEvent(name: string): void {
    setEventName(name)
    eventDropdownOpen.value = false
}

function update(patch: Record<string, unknown>) {
    emit('update:trigger', {
        ...currentTrigger.value,
        ...patch,
        config: {
            ...(currentTrigger.value.config as Record<string, unknown>),
            ...((patch.config as Record<string, unknown>) ?? {}),
        },
    })
}

function updateType(type: string) {
    emit('update:trigger', createDefaultTrigger(type))
}

function updateArray(key: string, index: number, value: string) {
    const cfg = currentTrigger.value.config as Record<string, unknown>
    const next = [...((cfg?.[key] as string[]) ?? [])]
    next[index] = value
    update({ config: { [key]: next } })
}

function addArrayItem(key: string) {
    const cfg = currentTrigger.value.config as Record<string, unknown>
    update({
        config: {
            [key]: [...((cfg?.[key] as string[]) ?? []), ''],
        },
    })
}

function removeArrayItem(key: string, index: number) {
    const cfg = currentTrigger.value.config as Record<string, unknown>
    update({
        config: {
            [key]: ((cfg?.[key] as string[]) ?? []).filter((_: string, i: number) => i !== index),
        },
    })
}

function removeTrigger() {
    emit('delete:trigger')
}

function createDefaultTrigger(type: string): Record<string, unknown> {
    return {
        type,
        is_active: true,
        priority: 100,
        config: defaultConfig(type),
    }
}

function defaultConfig(type: string): Record<string, unknown> {
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
    <div class="accordion">
        <AccordionSection title="General" default-open>
            <div class="config-field">
                <div class="field-label">Trigger type</div>
                <select
                    class="field-input"
                    :value="currentTrigger.type"
                    @change="updateType(($event.target as HTMLSelectElement).value)"
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
                    @input="update({ priority: Number.parseInt(($event.target as HTMLInputElement).value || '0', 10) || 0 })"
                >
            </div>

            <label class="toggle-row">
                <input
                    type="checkbox"
                    :checked="(currentTrigger.is_active as boolean) ?? false"
                    @change="update({ is_active: ($event.target as HTMLInputElement).checked })"
                >
                <span>Trigger is active</span>
            </label>
        </AccordionSection>

        <AccordionSection v-if="currentTrigger.type === 'message'" title="Message matching" default-open>
            <div class="config-field">
                <div class="field-label">Keywords</div>
                <div
                    v-for="(keyword, index) in ((currentTrigger.config as Record<string, unknown>)?.keywords as string[] ?? [])"
                    :key="`keyword-${index}`"
                    class="array-row"
                >
                    <input
                        class="field-input"
                        :value="keyword"
                        @input="updateArray('keywords', index, ($event.target as HTMLInputElement).value)"
                    >
                    <button type="button" class="delete-inline" @click="removeArrayItem('keywords', index)">×</button>
                </div>
                <button type="button" class="add-item-btn" @click="addArrayItem('keywords')">+ Add keyword</button>
            </div>

            <div class="config-field">
                <div class="field-label">Phrases</div>
                <div
                    v-for="(phrase, index) in ((currentTrigger.config as Record<string, unknown>)?.phrases as string[] ?? [])"
                    :key="`phrase-${index}`"
                    class="array-row"
                >
                    <input
                        class="field-input"
                        :value="phrase"
                        @input="updateArray('phrases', index, ($event.target as HTMLInputElement).value)"
                    >
                    <button type="button" class="delete-inline" @click="removeArrayItem('phrases', index)">×</button>
                </div>
                <button type="button" class="add-item-btn" @click="addArrayItem('phrases')">+ Add phrase</button>
            </div>
        </AccordionSection>

        <AccordionSection v-else-if="currentTrigger.type === 'schedule'" title="Schedule" default-open>
            <div class="config-field">
                <div class="field-label">Cron</div>
                <input
                    class="field-input"
                    :value="(triggerCfg.cron as string) ?? ''"
                    placeholder="0 9 * * 1-5"
                    @input="update({ config: { cron: ($event.target as HTMLInputElement).value } })"
                >
            </div>

            <div class="config-field">
                <div class="field-label">Timezone</div>
                <input
                    class="field-input"
                    :value="(triggerCfg.timezone as string) ?? 'UTC'"
                    @input="update({ config: { timezone: ($event.target as HTMLInputElement).value } })"
                >
            </div>
        </AccordionSection>

        <AccordionSection v-else-if="currentTrigger.type === 'webhook'" title="Webhook" default-open>
            <div class="config-field">
                <div class="field-label">Method</div>
                <select
                    class="field-input"
                    :value="(triggerCfg.method as string) ?? 'POST'"
                    @change="update({ config: { method: ($event.target as HTMLSelectElement).value } })"
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
                    :value="(triggerCfg.path as string) ?? ''"
                    placeholder="/webhooks/hr"
                    @input="update({ config: { path: ($event.target as HTMLInputElement).value } })"
                >
            </div>

            <div class="config-field">
                <div class="field-label">Secret</div>
                <input
                    class="field-input"
                    :value="(triggerCfg.secret as string) ?? ''"
                    @input="update({ config: { secret: ($event.target as HTMLInputElement).value } })"
                >
            </div>
        </AccordionSection>

        <AccordionSection v-else-if="currentTrigger.type === 'api'" title="API" default-open>
            <div class="config-field">
                <div class="field-label">Route key</div>
                <input
                    class="field-input"
                    :value="(triggerCfg.route_key as string) ?? ''"
                    placeholder="start_onboarding"
                    @input="update({ config: { route_key: ($event.target as HTMLInputElement).value } })"
                >
            </div>

            <div class="config-field">
                <div class="field-label">Allowed sources</div>
                <div
                    v-for="(source, index) in ((currentTrigger.config as Record<string, unknown>)?.allowed_sources as string[] ?? [])"
                    :key="`source-${index}`"
                    class="array-row"
                >
                    <input
                        class="field-input"
                        :value="source"
                        @input="updateArray('allowed_sources', index, ($event.target as HTMLInputElement).value)"
                    >
                    <button type="button" class="delete-inline" @click="removeArrayItem('allowed_sources', index)">×</button>
                </div>
                <button type="button" class="add-item-btn" @click="addArrayItem('allowed_sources')">+ Add source</button>
            </div>
        </AccordionSection>

        <AccordionSection v-else-if="currentTrigger.type === 'event'" title="Event" default-open>
            <div class="config-field">
                <div class="field-label">Event name</div>
                <div class="event-combo">
                    <input
                        type="text"
                        class="field-input"
                        :value="currentEventName"
                        placeholder="Search or type an event name…"
                        autocomplete="off"
                        @focus="eventDropdownOpen = true"
                        @input="setEventName(($event.target as HTMLInputElement).value)"
                        @keydown.escape="eventDropdownOpen = false"
                        @blur="eventDropdownOpen = false"
                    >
                    <div v-if="eventDropdownOpen && filteredEvents.length" class="event-dropdown">
                        <button
                            v-for="eventName in filteredEvents"
                            :key="eventName"
                            type="button"
                            class="event-option"
                            :class="{ active: eventName === currentEventName }"
                            @mousedown.prevent="selectEvent(eventName)"
                        >{{ eventName }}</button>
                    </div>
                    <div
                        v-else-if="eventDropdownOpen && currentEventName.trim()"
                        class="event-empty"
                    >
                        No matching event — it will be saved as a new event name.
                    </div>
                </div>
                <div class="field-hint">
                    Lists events from all assistants in this tenant. Pick one or type a new name.
                </div>
            </div>
        </AccordionSection>

        <AccordionSection title="Danger zone">
            <button type="button" class="delete-trigger-btn" @click="removeTrigger">Delete trigger</button>
        </AccordionSection>
    </div>
</template>

<style scoped>
.event-combo {
    position: relative;
}
.event-dropdown {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    z-index: 20;
    max-height: 220px;
    overflow-y: auto;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-md);
    padding: 4px;
}
.event-option {
    display: block;
    width: 100%;
    text-align: left;
    padding: 6px 8px;
    border: none;
    background: transparent;
    border-radius: 6px;
    font-family: 'Victor Mono', monospace;
    font-size: 12px;
    color: var(--text);
    cursor: pointer;
}
.event-option:hover {
    background: var(--surface-2);
}
.event-option.active {
    background: var(--primary-bg);
    color: var(--primary);
}
.event-empty {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    z-index: 20;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-md);
    padding: 8px;
    font-size: 11px;
    color: var(--text-3);
}
.toggle-row {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: var(--text-2);
    cursor: pointer;
}

.array-row {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
}

.delete-inline {
    border: none;
    background: transparent;
    color: var(--text-3);
    cursor: pointer;
    font-size: 18px;
    line-height: 1;
}
.delete-inline:hover { color: #e53e3e; }

.add-item-btn {
    width: 100%;
    margin-top: 4px;
    padding: 6px 10px;
    border: 1.5px dashed var(--border-2);
    border-radius: 6px;
    background: transparent;
    color: var(--text-3);
    font-family: 'DM Sans', sans-serif;
    font-size: 12px;
    cursor: pointer;
    transition: border-color .12s, color .12s, background .12s;
}
.add-item-btn:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--primary-bg);
}

.delete-trigger-btn {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--rose, #e53e3e);
    border-radius: 6px;
    background: transparent;
    color: var(--rose, #e53e3e);
    font-family: 'DM Sans', sans-serif;
    font-size: 12px;
    cursor: pointer;
    transition: background .12s, color .12s;
}
.delete-trigger-btn:hover {
    background: var(--rose, #e53e3e);
    color: #fff;
}

.field-hint {
    font-size: 11px;
    color: var(--text-3);
    margin-top: 4px;
    line-height: 1.4;
}
</style>
