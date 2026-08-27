<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import AccordionSection from '../AccordionSection.vue'
import KeyboardListEditor from './KeyboardListEditor.vue'
import VariableStorageEditor from '@builder/components/editor/variables/VariableStorageEditor.vue'
import {useKnownGroups} from '@builder/composables/useKnownGroups'
import {useBuilderStore} from '@builder/store/builderStore'
import {compileVariable, decodeInputVariable} from '@builder/utils/variableCompiler'
import {storedTypeForInput} from '@builder/utils/inputVariableType'
import type {Variable} from '@builder/dto/types'

interface KbButton {
    id: string
    label?: unknown
    row?: number
    order?: number
    type?: string
    value?: string
    [key: string]: unknown
}

const builderStore = useBuilderStore()

const props = defineProps({
    node:   { type: Object, required: true },
    schema: { type: Object, required: true },
})

const emit = defineEmits(['update:config'])

// Backend enum: App\Domains\Flow\Enums\InputExpectedType. Order matches the
// taxonomy doc — textual first, then select-like, platform-native, media.
const INPUT_TYPES = [
    { value: 'text',     label: 'Text' },
    { value: 'number',   label: 'Number' },
    { value: 'email',    label: 'Email' },
    { value: 'phone',    label: 'Phone' },
    { value: 'date',     label: 'Date' },
    { value: 'select',   label: 'Select (buttons)' },
    { value: 'confirm',  label: 'Yes / No' },
    { value: 'contact',  label: 'Contact (button)' },
    { value: 'location', label: 'Location (button)' },
    { value: 'file',     label: 'File' },
    { value: 'image',    label: 'Image' },
    { value: 'document', label: 'Document' },
    { value: 'video',    label: 'Video' },
    { value: 'voice',    label: 'Voice' },
    { value: 'audio',    label: 'Audio' },
]

// Phone formats come from the assistant's served countries (configured in
// assistant settings). The author may narrow to one or accept any of them.
const assistantCountries = computed(() => builderStore.availableCountries)
const phoneCountryOptions = computed(() => [
    { value: '', label: 'Any served country' },
    ...assistantCountries.value,
])

const knownGroups = useKnownGroups()

const baseLanguage = computed(() => builderStore.contentBaseLanguage)

const config       = computed(() => (props.node.config ?? {}) as Record<string, unknown>)
const expectedType = computed(() => (config.value.expected_type as string | undefined) ?? 'text')
const isSelect     = computed(() => expectedType.value === 'select' || expectedType.value === 'confirm')
const buttons      = computed(() => (Array.isArray(config.value.buttons) ? config.value.buttons : []) as KbButton[])
const validation   = computed(() => (config.value.validation as Record<string, unknown> | undefined) ?? {})

function localizedFieldValue(raw: unknown): string {
    if (raw == null) return ''
    if (typeof raw === 'string') return raw
    if (typeof raw === 'object') {
        const map = raw as Record<string, unknown>
        return String(map[baseLanguage.value] ?? Object.values(map)[0] ?? '')
    }
    return String(raw)
}

function emitLocalizedField(key: string, value: string) {
    const existing = props.node.config?.[key]
    if (existing && typeof existing === 'object') {
        emit('update:config', {[key]: {...(existing as Record<string, unknown>), [baseLanguage.value]: value}})
        return
    }
    emit('update:config', {[key]: {[baseLanguage.value]: value}})
}

// Inside a loop body, freshly-added inputs default to "store as list" so each
// iteration accumulates instead of overwriting. Only seeds brand-new variables.
const inLoop = computed(() => builderStore.isNodeInLoopBody(String(props.node.id)))

// Read-only type caption for the variable card. Shows what is actually STORED
// (via storedTypeForInput) — a "photo" input stores a media reference, not a
// photo. Wrapped in "List of …" when accumulating.
const variableTypeDisplay = computed(() => {
    const { label } = storedTypeForInput(expectedType.value)
    return variable.value.isList ? `List of: ${label}` : label
})

function decodeVariableWithLoopDefault(): Variable {
    const cfg = (props.node.config ?? {}) as Record<string, unknown>
    const decoded = decodeInputVariable(cfg)
    if (!cfg.variable && inLoop.value && !decoded.isList) {
        decoded.isList = true
    }
    return decoded
}

const variable = ref<Variable>(decodeVariableWithLoopDefault())

watch(
    () => props.node.id,
    () => {
        variable.value = decodeVariableWithLoopDefault()
    },
)

function update(key: string, value: unknown) {
    emit('update:config', { [key]: value })
}

function updateValidation(patch: Record<string, unknown>) {
    const next = { ...validation.value, ...patch }
    // Strip empty/null keys so the persisted config stays tight.
    for (const k of Object.keys(next)) {
        const v = next[k]
        if (v === '' || v === null || v === undefined) delete next[k]
    }
    emit('update:config', { validation: next })
}

function onVariableUpdate(next: Variable) {
    variable.value = next
    emit('update:config', {
        variable: compileVariable(next),
        save_to:  undefined,
    })
}

function updateConfirmLabel(idx: number, label: string) {
    const next = buttons.value.map((btn, i) =>
        i === idx ? { ...btn, label: { ...((typeof btn.label === 'object' && btn.label !== null ? btn.label : {}) as Record<string, unknown>), [baseLanguage.value]: label } } : btn
    )
    update('buttons', next)
}

function seedConfirmButtons() {
    const yesId = crypto.randomUUID()
    const noId  = crypto.randomUUID()
    emit('update:config', {
        buttons: [
            { id: yesId, type: 'callback', label: { [baseLanguage.value]: 'Yes' }, value: 'yes', row: 0, order: 0 },
            { id: noId,  type: 'callback', label: { [baseLanguage.value]: 'No'  }, value: 'no',  row: 0, order: 1 },
        ],
    })
}

function onTypeChange(newType: string) {
    // Seed buttons on first switch into confirm so the user has a working keyboard
    // immediately. The seed call emits its own config patch — we then emit
    // expected_type separately so both land in the store.
    if (newType === 'confirm' && buttons.value.length === 0) {
        seedConfirmButtons()
    }

    // Keep variable.type in sync so the variable schema registry stores the
    // right type — the STORED type, not the raw expected_type (a photo input
    // stores a media-reference object, not a "photo").
    const updatedVariable = { ...variable.value, type: storedTypeForInput(newType).type }
    variable.value = updatedVariable

    const patch: Record<string, unknown> = {
        expected_type: newType,
        variable:      compileVariable(updatedVariable),
    }
    if (Object.keys(validation.value).length > 0) {
        patch.validation = {}
    }
    emit('update:config', patch)
}
</script>

<template>
    <div class="accordion">
        <AccordionSection default-open title="Prompt">
            <div class="config-field">
                <div class="field-label">Question</div>
                <textarea
                    :value="localizedFieldValue(props.node.config?.prompt)"
                    class="field-input"
                    placeholder="What the bot asks before waiting (e.g. «Send me your phone number»)"
                    rows="3"
                    @input="emitLocalizedField('prompt', ($event.target as HTMLTextAreaElement).value)"
                />
                <p class="field-help">
                    Sent right before the bot starts waiting. For Select / Confirm the prompt is sent
                    together with the inline keyboard.
                </p>
            </div>
        </AccordionSection>

        <AccordionSection title="Expected input" default-open>
            <div class="config-field">
                <div class="field-label">Type</div>
                <select
                    class="field-input"
                    :value="expectedType"
                    @change="onTypeChange(($event.target as HTMLSelectElement).value)"
                >
                    <option v-for="opt in INPUT_TYPES" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
                <p class="field-help">
                    Drives validation and how the bot waits. Select / Confirm render an inline keyboard.
                    Contact / Location require the user to share via Telegram's native button.
                </p>
            </div>

            <!-- Yes / No: only label editing, values are fixed -->
            <template v-if="expectedType === 'confirm'">
                <div class="config-field">
                    <div class="field-label">Button labels</div>
                    <div style="display:flex;flex-direction:column;gap:6px">
                        <div v-for="(btn, idx) in buttons" :key="btn.id" style="display:flex;align-items:center;gap:8px">
                            <span style="font-size:11px;color:var(--color-text-muted,#888);width:28px;text-align:right;flex-shrink:0">
                                {{ btn.value }}
                            </span>
                            <input
                                class="field-input"
                                style="flex:1"
                                :value="localizedFieldValue(btn.label)"
                                @input="updateConfirmLabel(idx, ($event.target as HTMLInputElement).value)"
                            >
                        </div>
                    </div>
                    <p class="field-help">
                        The value stored on press (<em>yes</em> / <em>no</em>) is fixed. Only the visible label is editable.
                    </p>
                </div>
            </template>

            <!-- Select: full keyboard editor -->
            <template v-if="expectedType === 'select'">
                <div class="config-field">
                    <div class="field-label">Buttons</div>
                    <KeyboardListEditor
                        :buttons="buttons"
                        :is-reply-keyboard="false"
                        save-to-type="string"
                        @update="update('buttons', $event)"
                    />
                    <p class="field-help">
                        The user must press one of these buttons. The pressed button's <em>value</em> is stored
                        in the variable above. All buttons route through the <em>default</em> output —
                        branch downstream with a condition node if you need per-value paths.
                    </p>
                </div>
            </template>

            <template v-if="expectedType === 'text'">
                <div class="config-field" style="display:flex;gap:8px">
                    <div style="flex:1">
                        <div class="field-label">Min length</div>
                        <input
                            class="field-input"
                            type="number"
                            min="0"
                            :value="validation.min_length ?? ''"
                            @input="updateValidation({ min_length: ($event.target as HTMLInputElement).value === '' ? null : Number(($event.target as HTMLInputElement).value) })"
                        >
                    </div>
                    <div style="flex:1">
                        <div class="field-label">Max length</div>
                        <input
                            class="field-input"
                            type="number"
                            min="0"
                            :value="validation.max_length ?? ''"
                            @input="updateValidation({ max_length: ($event.target as HTMLInputElement).value === '' ? null : Number(($event.target as HTMLInputElement).value) })"
                        >
                    </div>
                </div>
                <div class="config-field">
                    <div class="field-label">Pattern (PHP regex)</div>
                    <input
                        class="field-input"
                        style="font-family:'Victor Mono',monospace;font-size:12px"
                        placeholder="/^[A-Z]{3}-\d+$/"
                        :value="validation.pattern ?? ''"
                        @input="updateValidation({ pattern: ($event.target as HTMLInputElement).value })"
                    >
                </div>
            </template>

            <template v-if="expectedType === 'number'">
                <div class="config-field" style="display:flex;gap:8px">
                    <div style="flex:1">
                        <div class="field-label">Min</div>
                        <input
                            class="field-input"
                            type="number"
                            :value="validation.min ?? ''"
                            @input="updateValidation({ min: ($event.target as HTMLInputElement).value === '' ? null : Number(($event.target as HTMLInputElement).value) })"
                        >
                    </div>
                    <div style="flex:1">
                        <div class="field-label">Max</div>
                        <input
                            class="field-input"
                            type="number"
                            :value="validation.max ?? ''"
                            @input="updateValidation({ max: ($event.target as HTMLInputElement).value === '' ? null : Number(($event.target as HTMLInputElement).value) })"
                        >
                    </div>
                </div>
                <div class="config-field">
                    <label style="display:flex;gap:6px;align-items:center;font-size:13px">
                        <input
                            type="checkbox"
                            :checked="!!validation.integer_only"
                            @change="updateValidation({ integer_only: ($event.target as HTMLInputElement).checked || null })"
                        >
                        Integer only
                    </label>
                </div>
            </template>

            <template v-if="expectedType === 'phone'">
                <div class="config-field">
                    <div class="field-label">Country</div>
                    <template v-if="assistantCountries.length > 0">
                        <select
                            class="field-input"
                            :value="validation.country ?? ''"
                            @change="updateValidation({ country: ($event.target as HTMLSelectElement).value || null })"
                        >
                            <option v-for="c in phoneCountryOptions" :key="c.value" :value="c.value">{{ c.label }}</option>
                        </select>
                        <p class="field-help">
                            Formats come from the assistant's served countries. Leave “Any served country”
                            to accept a number from any of them, or narrow to one. National format is
                            normalized to E.164.
                        </p>
                    </template>
                    <p v-else class="field-help" style="color: var(--amber, #9a6a00)">
                        No countries configured for this assistant. Add them in
                        <strong>Assistant settings → Served countries</strong> to validate phone formats.
                    </p>
                </div>
            </template>

            <template v-if="expectedType === 'date'">
                <div class="config-field">
                    <div class="field-label">Date format</div>
                    <select
                        class="field-input"
                        :value="validation.format ?? 'Y-m-d'"
                        @change="updateValidation({ format: ($event.target as HTMLSelectElement).value })"
                    >
                        <optgroup label="Year-Month-Day">
                            <option value="Y-m-d">2026-05-31</option>
                            <option value="Y.m.d">2026.05.31</option>
                            <option value="Y/m/d">2026/05/31</option>
                        </optgroup>
                        <optgroup label="Day-Month-Year">
                            <option value="d-m-Y">31-05-2026</option>
                            <option value="d.m.Y">31.05.2026</option>
                            <option value="d/m/Y">31/05/2026</option>
                        </optgroup>
                        <optgroup label="Month-Day-Year">
                            <option value="m-d-Y">05-31-2026</option>
                            <option value="m.d.Y">05.31.2026</option>
                            <option value="m/d/Y">05/31/2026</option>
                        </optgroup>
                        <optgroup label="Short year">
                            <option value="d-m-y">31-05-26</option>
                            <option value="d.m.y">31.05.26</option>
                            <option value="d/m/y">31/05/26</option>
                        </optgroup>
                    </select>
                    <p class="field-help">Stored value is always normalized to <code>Y-m-d</code>.</p>
                </div>
            </template>

            <template v-if="expectedType === 'contact' || expectedType === 'location'">
                <div class="config-field">
                    <div class="field-label">Button label</div>
                    <input
                        class="field-input"
                        :value="localizedFieldValue(props.node.config?.request_button_label)"
                        :placeholder="expectedType === 'contact' ? '📱 Share contact' : '📍 Share location'"
                        @input="emitLocalizedField('request_button_label', ($event.target as HTMLInputElement).value)"
                    >
                    <p class="field-help">
                        The input node sends the <em>prompt</em> above together with this native Telegram button.
                        When pressed, Telegram sends the user's
                        {{ expectedType === 'contact' ? 'phone number and name' : 'GPS coordinates' }}
                        back to the bot automatically.
                        {{ expectedType === 'location' ? 'Stored as { latitude, longitude }.' : '' }}
                    </p>
                </div>
            </template>
        </AccordionSection>

        <AccordionSection title="Variable" default-open>
            <VariableStorageEditor
                :model-value="variable"
                :type-options="INPUT_TYPES"
                :known-groups="knownGroups"
                :owner-node-id="String(props.node.id)"
                :show-type="false"
                :type-display="variableTypeDisplay"
                show-storage
                show-group
                show-list
                @update:model-value="onVariableUpdate"
            />
        </AccordionSection>

        <AccordionSection title="Retry / fallback">
            <div class="config-field">
                <div class="field-label">Retry limit</div>
                <input
                    class="field-input"
                    type="number"
                    min="0"
                    style="width:100px"
                    :value="props.node.config?.retry_limit ?? 3"
                    @input="update('retry_limit', ($event.target as HTMLInputElement).value === '' ? null : Number(($event.target as HTMLInputElement).value))"
                >
                <p class="field-help">
                    How many invalid attempts to accept before routing to the <em>invalid</em> output.
                </p>
            </div>
            <div class="config-field">
                <div class="field-label">On invalid message</div>
                <input
                    class="field-input"
                    :value="localizedFieldValue(props.node.config?.on_invalid_message)"
                    placeholder="Please enter a valid value"
                    @input="emitLocalizedField('on_invalid_message', ($event.target as HTMLInputElement).value)"
                >
                <p class="field-help">Sent on each failed attempt while retries remain.</p>
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
                >
            </div>
        </AccordionSection>
    </div>
</template>
