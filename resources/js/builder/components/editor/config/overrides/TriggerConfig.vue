<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import AccordionSection from '../AccordionSection.vue'
import {createWebhookEndpoint, generateWebhookSecret, setWebhookSecret} from '@builder/api/builderApi'
import type {ApiError} from '@builder/api/builderApi'
import type {WebhookEndpointInfo} from '@builder/dto/types'
import {useConfirm} from '@builder/composables/useConfirm'
import {
    WEBHOOK_PRESETS,
    defaultWebhookConfig,
    isLegacyWebhookConfig,
    webhookConfigFromPreset,
    withWebhookField,
    withWebhookVerification,
} from '@builder/utils/webhookTrigger'

const props = defineProps({
    trigger: { type: Object as () => Record<string, unknown> | null, default: null },
    availableEvents: { type: Array as () => string[], default: () => [] },
    flowId: { type: String as () => string | null, default: null },
    webhookEndpoints: { type: Object as () => Record<string, WebhookEndpointInfo>, default: () => ({}) },
    readOnly: { type: Boolean, default: false },
    /** Pushes the unsaved draft to the server; resolves false when it could not be saved. */
    flushDraft: { type: Function as unknown as () => () => Promise<boolean>, default: null },
})

const emit = defineEmits(['update:trigger', 'delete:trigger', 'webhook-endpoint'])

const { confirm } = useConfirm()

const triggerTypes = [
    { value: 'message', label: 'Message' },
    { value: 'schedule', label: 'Schedule' },
    { value: 'webhook', label: 'Webhook' },
    { value: 'api', label: 'API' },
    { value: 'event', label: 'Event' },
]

const currentTrigger = computed(() =>
    props.trigger ?? createDefaultTrigger('message')
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

/**
 * Webhook config edits replace the whole config (a plain `update` merges, so it could not drop a key).
 */
function setWebhookConfig(config: Record<string, unknown>): void {
    emit('update:trigger', { ...currentTrigger.value, config })
}

const webhookLegacy = computed(() => isLegacyWebhookConfig(triggerCfg.value))
const webhookIsHmac = computed(() => triggerCfg.value.verification === 'hmac_sha256')

function webhookText(key: string): string {
    const value = triggerCfg.value[key]

    return typeof value === 'string' ? value : ''
}

function webhookNumber(key: string): string {
    const value = triggerCfg.value[key]

    return typeof value === 'number' ? String(value) : ''
}

function setWebhookField(key: string, value: string): void {
    setWebhookConfig(withWebhookField(triggerCfg.value, key, value.trim() === '' ? null : value.trim()))
}

function setWebhookNumberField(key: string, value: string): void {
    const parsed = Number.parseInt(value, 10)

    setWebhookConfig(withWebhookField(triggerCfg.value, key, Number.isNaN(parsed) ? null : parsed))
}

const webhookPresetValue = ref('')

function applyWebhookPreset(value: string): void {
    if (value === '') {
        return
    }

    setWebhookConfig(webhookConfigFromPreset(value))
    webhookPresetValue.value = ''
}

const triggerId = computed(() => String(currentTrigger.value.id ?? ''))
const endpoint = computed<WebhookEndpointInfo | null>(() => props.webhookEndpoints[triggerId.value.toLowerCase()] ?? null)

const webhookBusy = ref(false)
const webhookError = ref<string | null>(null)
const generatedSecret = ref<string | null>(null)
const pastedSecret = ref('')
const copied = ref<'url' | 'secret' | null>(null)

watch(triggerId, () => {
    generatedSecret.value = null
    pastedSecret.value = ''
    webhookError.value = null
    copied.value = null
})

const secretRotatedLabel = computed(() => {
    const at = endpoint.value?.secret_rotated_at

    return at ? new Date(at).toLocaleString() : null
})

function errorMessage(error: unknown): string {
    const err = error as ApiError & { body?: { errors?: Record<string, string[]> } }
    const first = err.body?.errors ? Object.values(err.body.errors)[0]?.[0] : null

    return first ?? err.message ?? 'Request failed'
}

/** The trigger must be in the saved draft before the server can mint a URL or secret for it. */
async function runWebhookAction(action: () => Promise<void>): Promise<void> {
    if (props.flowId === null || triggerId.value === '' || webhookBusy.value) {
        return
    }

    webhookBusy.value = true
    webhookError.value = null

    try {
        if (props.flushDraft !== null && !(await props.flushDraft())) {
            webhookError.value = 'The draft could not be saved. Fix the problem and try again.'

            return
        }

        await action()
    } catch (error) {
        webhookError.value = errorMessage(error)
    } finally {
        webhookBusy.value = false
    }
}

function getWebhookUrl(): Promise<void> {
    return runWebhookAction(async () => {
        emit('webhook-endpoint', triggerId.value, await createWebhookEndpoint(props.flowId as string, triggerId.value))
    })
}

async function generateSecret(): Promise<void> {
    if (endpoint.value?.has_secret && !(await confirm({
        title: 'Replace secret',
        message: 'The current secret stops working at once. Senders must be updated with the new one.',
        confirmLabel: 'Replace',
        danger: true,
    }))) {
        return
    }

    await runWebhookAction(async () => {
        const result = await generateWebhookSecret(props.flowId as string, triggerId.value)
        const { secret, ...info } = result

        emit('webhook-endpoint', triggerId.value, info)
        generatedSecret.value = secret
    })
}

async function savePastedSecret(): Promise<void> {
    const value = pastedSecret.value.trim()

    if (value === '') {
        return
    }

    if (endpoint.value?.has_secret && !(await confirm({
        title: 'Replace secret',
        message: 'The current secret stops working at once.',
        confirmLabel: 'Replace',
        danger: true,
    }))) {
        return
    }

    await runWebhookAction(async () => {
        const { secret: _shown, ...info } = await setWebhookSecret(props.flowId as string, triggerId.value, value)

        emit('webhook-endpoint', triggerId.value, info)
        generatedSecret.value = null
        pastedSecret.value = ''
    })
}

async function copyText(kind: 'url' | 'secret', text: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text)
        copied.value = kind
        setTimeout(() => { copied.value = null }, 1500)
    } catch {
        webhookError.value = 'Copying failed. Select the text and copy it by hand.'
    }
}

function updateType(type: string) {
    emit('update:trigger', { ...createDefaultTrigger(type), id: currentTrigger.value.id })
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
            return defaultWebhookConfig()
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
            <div v-if="webhookError" class="webhook-error" role="alert">{{ webhookError }}</div>

            <div class="config-field">
                <div class="field-label">URL</div>
                <div v-if="endpoint" class="webhook-row">
                    <input class="field-input webhook-mono" type="text" readonly :value="endpoint.url" @focus="($event.target as HTMLInputElement).select()">
                    <button type="button" class="webhook-btn" @click="copyText('url', endpoint.url)">
                        {{ copied === 'url' ? 'Copied' : 'Copy' }}
                    </button>
                </div>
                <button v-else type="button" class="webhook-btn" :disabled="readOnly || webhookBusy" @click="getWebhookUrl">
                    {{ webhookBusy ? 'Working…' : 'Get URL' }}
                </button>
                <div class="field-hint">Requests are accepted once the flow is published with this trigger active.</div>
            </div>

            <div v-if="endpoint" class="config-field">
                <div class="field-label">Secret</div>
                <div v-if="endpoint.has_secret" class="field-hint webhook-status">
                    Secret set<template v-if="secretRotatedLabel"> · {{ secretRotatedLabel }}</template>
                </div>
                <div v-else class="field-hint webhook-status-warn">
                    No secret yet — requests are refused until one is set.
                </div>

                <div v-if="generatedSecret" class="webhook-secret-box">
                    <div class="webhook-row">
                        <input class="field-input webhook-mono" type="text" readonly :value="generatedSecret" @focus="($event.target as HTMLInputElement).select()">
                        <button type="button" class="webhook-btn" @click="copyText('secret', generatedSecret)">
                            {{ copied === 'secret' ? 'Copied' : 'Copy' }}
                        </button>
                    </div>
                    <div class="field-hint webhook-status-warn">Copy it now — it will not be shown again.</div>
                </div>

                <button type="button" class="webhook-btn" :disabled="readOnly || webhookBusy" @click="generateSecret">
                    {{ endpoint.has_secret ? 'Replace secret' : 'Generate secret' }}
                </button>

                <div class="field-label webhook-sub">Use a secret from the sender</div>
                <div class="webhook-row">
                    <input
                        v-model="pastedSecret"
                        class="field-input webhook-mono"
                        type="password"
                        autocomplete="off"
                        placeholder="16–512 characters, no spaces"
                    >
                    <button type="button" class="webhook-btn" :disabled="readOnly || webhookBusy || pastedSecret.trim() === ''" @click="savePastedSecret">Save</button>
                </div>
            </div>

            <div class="config-field">
                <div class="field-label">Verification preset</div>
                <div v-if="webhookLegacy" class="webhook-error">
                    This trigger uses an old format; pick a verification preset to update it.
                </div>
                <select
                    class="field-input"
                    :value="webhookPresetValue"
                    @change="applyWebhookPreset(($event.target as HTMLSelectElement).value)"
                >
                    <option value="">Choose a preset…</option>
                    <option v-for="preset in WEBHOOK_PRESETS" :key="preset.value" :value="preset.value">{{ preset.label }}</option>
                </select>
                <div class="field-hint">A preset replaces the verification settings below.</div>
            </div>

            <template v-if="!webhookLegacy">
                <div class="config-field">
                    <div class="field-label">Verification</div>
                    <select
                        class="field-input"
                        :value="webhookText('verification')"
                        @change="setWebhookConfig(withWebhookVerification(triggerCfg, ($event.target as HTMLSelectElement).value))"
                    >
                        <option value="hmac_sha256">HMAC-SHA256</option>
                        <option value="header_equals">Secret in a header</option>
                    </select>
                </div>

                <div class="config-field">
                    <div class="field-label">Signature header</div>
                    <input
                        class="field-input"
                        :value="webhookText('signature_header')"
                        placeholder="X-Signature"
                        @input="setWebhookField('signature_header', ($event.target as HTMLInputElement).value)"
                    >
                </div>

                <template v-if="webhookIsHmac">
                    <div class="config-field">
                        <div class="field-label">Signature prefix</div>
                        <input
                            class="field-input"
                            :value="webhookText('signature_prefix')"
                            placeholder="sha256="
                            @input="setWebhookField('signature_prefix', ($event.target as HTMLInputElement).value)"
                        >
                    </div>

                    <div class="config-field">
                        <div class="field-label">Timestamp header</div>
                        <input
                            class="field-input"
                            :value="webhookText('timestamp_header')"
                            placeholder="X-Timestamp"
                            @input="setWebhookField('timestamp_header', ($event.target as HTMLInputElement).value)"
                        >
                    </div>

                    <div v-if="webhookText('timestamp_header') !== '' || webhookText('signed_payload') !== ''" class="config-field">
                        <div class="field-label">Signed string</div>
                        <input
                            class="field-input webhook-mono"
                            :value="webhookText('signed_payload')"
                            placeholder="v0:{header.X-Timestamp}:{raw}"
                            @input="setWebhookField('signed_payload', ($event.target as HTMLInputElement).value)"
                        >
                        <div class="field-hint">
                            What the sender signs. Must contain {raw} (the body) and the timestamp header, e.g. v0:{header.X-Timestamp}:{raw}
                        </div>
                    </div>

                    <div v-if="webhookText('timestamp_header') !== ''" class="config-field">
                        <div class="field-label">Timestamp tolerance (seconds)</div>
                        <input
                            class="field-input"
                            type="number"
                            min="1"
                            max="3600"
                            :value="webhookNumber('timestamp_tolerance')"
                            placeholder="300"
                            @input="setWebhookNumberField('timestamp_tolerance', ($event.target as HTMLInputElement).value)"
                        >
                    </div>
                </template>

                <div class="config-field">
                    <div class="field-label">Delivery id</div>
                    <input
                        class="field-input"
                        :value="webhookText('delivery_id')"
                        placeholder="{header.X-Delivery-Id}"
                        @input="setWebhookField('delivery_id', ($event.target as HTMLInputElement).value)"
                    >
                    <div class="field-hint">
                        One placeholder: <code>{header.Name}</code> or <code>{body.path.to.id}</code>; separate alternatives with <code>|</code>.
                    </div>
                    <div v-if="webhookText('delivery_id') === ''" class="field-hint webhook-status-warn">
                        Without a delivery id, a resent request starts the flow again.
                    </div>
                </div>
            </template>
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
.webhook-row {
    display: flex;
    gap: 6px;
    align-items: center;
    margin-bottom: 6px;
}
.webhook-mono {
    font-family: var(--font-mono);
    font-size: 12px;
}
.webhook-btn {
    padding: 6px 10px;
    border: 1px solid var(--border-2);
    border-radius: 6px;
    background: var(--surface);
    color: var(--text);
    font-family: var(--font-sans);
    font-size: 12px;
    cursor: pointer;
    white-space: nowrap;
}
.webhook-btn:hover:not(:disabled) {
    border-color: var(--primary);
    color: var(--primary);
}
.webhook-btn:disabled {
    opacity: .5;
    cursor: not-allowed;
}
.webhook-sub {
    margin-top: 10px;
}
.webhook-secret-box {
    margin-bottom: 8px;
}
.webhook-status-warn {
    color: var(--error);
}
.webhook-error {
    margin-bottom: 8px;
    padding: 6px 8px;
    border: 1px solid var(--error);
    border-radius: 6px;
    font-size: 11px;
    color: var(--error);
}
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
    font-family: var(--font-mono);
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
.delete-inline:hover { color: var(--error); }

.add-item-btn {
    width: 100%;
    margin-top: 4px;
    padding: 6px 10px;
    border: 1.5px dashed var(--border-2);
    border-radius: 6px;
    background: transparent;
    color: var(--text-3);
    font-family: var(--font-sans);
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
    border: 1px solid var(--rose);
    border-radius: 6px;
    background: transparent;
    color: var(--rose);
    font-family: var(--font-sans);
    font-size: 12px;
    cursor: pointer;
    transition: background .12s, color .12s;
}
.delete-trigger-btn:hover {
    background: var(--rose);
    color: var(--on-solid);
}

.field-hint {
    font-size: 11px;
    color: var(--text-3);
    margin-top: 4px;
    line-height: 1.4;
}
</style>
