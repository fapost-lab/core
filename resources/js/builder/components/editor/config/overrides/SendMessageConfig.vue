<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import {useBuilderStore} from '@builder/store/builderStore'
import {useInsertAtCursor} from '@builder/composables/useInsertAtCursor'
import {useKnownGroups} from '@builder/composables/useKnownGroups'
import KeyboardListEditor from './KeyboardListEditor.vue'
import VariablePicker from '../VariablePicker.vue'
import AccordionSection from '../AccordionSection.vue'
import StatePathPicker from '../StatePathPicker.vue'
import MediaPicker from '@builder/components/media/MediaPicker.vue'
import VariableStorageEditor from '@builder/components/editor/variables/VariableStorageEditor.vue'
import {
    compileVariable,
    decodeSendMessageVariable,
    variableTypeToLegacySaveToType,
} from '@builder/utils/variableCompiler'
import type {Variable} from '@builder/dto/types'

const DYNAMIC_ITEM_TYPES = [
    { value: 'text', label: 'Text' },
]

// Aligned with SendMessage button-answer taxonomy. Only three types make
// sense for a tap-on-button scenario: free text, numeric label, or yes/no.
const BUTTON_VALUE_TYPES = [
    { value: 'text',    label: 'Text' },
    { value: 'number',  label: 'Number' },
    { value: 'confirm', label: 'Yes/No' },
]

interface KbButton {
    id: string
    type?: string
    [key: string]: unknown
}

interface MediaFile {
    id: string
    name: string
    kind: string
    preview_url: string | null
    [key: string]: unknown
}

const props = defineProps({
    node:   { type: Object as () => Record<string, unknown>, required: true },
    schema: { type: Object as () => Record<string, unknown>, required: true },
})

const emit = defineEmits(['update:config'])
const builderStore = useBuilderStore()

const config = computed((): Record<string, unknown> => (props.node.config as Record<string, unknown>) ?? {})
const buttons = computed((): KbButton[] => (config.value.buttons as KbButton[]) ?? [])
const contentType = computed(() => String(config.value.content_type ?? 'text'))
const keyboardMode = computed(() => String(config.value.keyboard_mode ?? 'inline'))
const showsButtons = computed(() => contentType.value === 'text_with_keyboard')
const showsMediaUrl = computed(() => ['image', 'document', 'video', 'voice'].includes(contentType.value))
const showsCaption = computed(() => ['image', 'document', 'video'].includes(contentType.value))
const mediaPickerKind = computed((): string | null => {
    const map: Record<string, string> = { image: 'image', video: 'video', document: 'document', voice: 'audio' }
    return map[contentType.value] ?? null
})
const mediaFile = computed(() => (config.value.media_file as MediaFile | null) ?? null)

function selectMediaFile(file: MediaFile | null) {
    if (file) {
        update({ media_file: file, media_file_id: file.id, media_url: undefined, media_path: undefined })
    } else {
        update({ media_file: null, media_file_id: undefined })
    }
}
const isReplyKeyboard = computed(() => showsButtons.value && keyboardMode.value === 'reply')

const knownGroups = useKnownGroups()

// ── Static keyboard ──────────────────────────────────────────────────────────

// `null` = user has not configured a save target. We surface an empty
// editor in that case so they can opt-in without any pre-filled name.
const answerVariable = ref<Variable | null>(decodeSendMessageVariable(config.value))

// Saving the button answer is opt-in. Toggle defaults to `true` for any
// node that already has a save target configured (legacy or new shape),
// and to `false` for fresh nodes — so authors don't see a half-filled
// editor demanding a variable name on every new send_message.
const saveAnswerEnabled = ref<boolean>(answerVariable.value !== null)

watch(
    () => props.node.id,
    () => {
        answerVariable.value = decodeSendMessageVariable(config.value)
        saveAnswerEnabled.value = answerVariable.value !== null
        syncDynamicRefs()
    },
)

// ── Dynamic keyboard ──────────────────────────────────────────────────────────

const isDynamic = computed(() =>
    showsButtons.value && !isReplyKeyboard.value && config.value.dynamic_buttons != null
)

// Switching to Dynamic is blocked when static buttons have wired descendants —
// removing buttons would prune those edges and disconnect graph nodes.
const dynamicSwitchBlocked = computed(() => {
    if (isDynamic.value) return false
    const nodeId   = props.node.id as string
    const buttonIds = new Set(buttons.value.map((b) => b.id).filter(Boolean))
    if (buttonIds.size === 0) return false
    return builderStore.definition.edges.some(
        (e) => e.from === nodeId && buttonIds.has(e.handle ?? ''),
    )
})

const dynamicConfig = computed(
    () => (config.value.dynamic_buttons as Record<string, unknown> | null) ?? {}
)

const dynamicSource = ref<string>(String(dynamicConfig.value.source ?? ''))

const dynamicItemVariable = ref<Variable | null>(
    (() => {
        const raw = (dynamicConfig.value.save_item_to as Record<string, unknown> | null) ?? null
        return raw ? (raw as Variable) : null
    })()
)

function syncDynamicRefs() {
    const dc = (config.value.dynamic_buttons as Record<string, unknown> | null) ?? {}
    dynamicSource.value = String(dc.source ?? '')
    const raw = (dc.save_item_to as Record<string, unknown> | null) ?? null
    dynamicItemVariable.value = raw ? (raw as Variable) : null
}

function switchKeyboardType(type: 'static' | 'dynamic') {
    if (type === 'dynamic') {
        // Do NOT clear buttons — they carry the graph edges that connect downstream
        // nodes. Clearing buttons would trigger edge pruning in the store and
        // disconnect all wired branches. The backend ignores buttons when
        // dynamic_buttons is present.
        update({
            dynamic_buttons: { source: '', max_per_row: 2, save_item_to: null },
        })
        dynamicSource.value = ''
        dynamicItemVariable.value = null
    } else {
        update({ dynamic_buttons: null })
    }
}

function updateDynamicSource(value: string) {
    dynamicSource.value = value
    update({ dynamic_buttons: { ...dynamicConfig.value, source: value } })
}

function updateDynamicMaxPerRow(value: string) {
    const n = parseInt(value, 10)
    update({ dynamic_buttons: { ...dynamicConfig.value, max_per_row: isNaN(n) ? 2 : Math.max(1, n) } })
}

function onDynamicItemVariableUpdate(next: Variable) {
    dynamicItemVariable.value = next
    update({ dynamic_buttons: { ...dynamicConfig.value, save_item_to: compileVariable(next) } })
}

// KeyboardListEditor still validates per-button values against the legacy
// string|number|boolean taxonomy. Map our richer VariableType down so that
// component keeps working unchanged.
const saveToType = computed(() => variableTypeToLegacySaveToType(answerVariable.value?.type))

function update(patch: unknown) {
    emit('update:config', patch)
}

function onAnswerVariableUpdate(next: Variable) {
    answerVariable.value = next
    // SaveDraft persists whatever the UI shows — even half-filled
    // descriptors. Publish + the Validate button surface empty names
    // as proper validation errors; we don't pre-filter them here.
    emit('update:config', {
        save_to_variable: compileVariable(next),
        save_to:          undefined,
        save_to_type:     undefined,
    })
}

function onSaveAnswerToggle(enabled: boolean) {
    saveAnswerEnabled.value = enabled
    if (!enabled) {
        // Clear all save-target keys so the backend treats the button press
        // as a pure routing event without persisting an answer.
        answerVariable.value = null
        emit('update:config', {
            save_to_variable: undefined,
            save_to:          undefined,
            save_to_type:     undefined,
        })
    }
}

// Refs + cursor-insert handlers for the variable picker. Each textarea
// owns its own ref so click-to-insert lands in the field next to the
// picker that triggered it; clipboard + drag&drop continue to work
// for cross-field reuse.
const textRef    = ref<HTMLTextAreaElement | null>(null)
const captionRef = ref<HTMLTextAreaElement | null>(null)
const insertText    = useInsertAtCursor(textRef,    (next) => update({ text: next }))
const insertCaption = useInsertAtCursor(captionRef, (next) => update({ caption: next }))

function dropInsert(e: DragEvent, currentValue: string): string | null {
    e.preventDefault()
    const snippet = e.dataTransfer?.getData('text/plain')
    if (!snippet) return null
    const el = e.target as HTMLTextAreaElement
    const at = el.selectionStart ?? currentValue.length
    return currentValue.slice(0, at) + snippet + currentValue.slice(el.selectionEnd ?? at)
}

function updateContentType(value: string) {
    const patch: Record<string, unknown> = { content_type: value }

    if (value !== 'text_with_keyboard') {
        patch.keyboard_mode = null
        patch.buttons = []
        patch.timeout_seconds = null
    } else {
        patch.keyboard_mode = config.value.keyboard_mode ?? 'inline'
    }

    update(patch)
}

function updateKeyboardMode(value: string) {
    if (value === 'reply') {
        builderStore.definition.edges = builderStore.definition.edges.filter(
            (edge) => edge.from !== props.node.id,
        )
    }

    update({
        keyboard_mode: value,
        buttons: buttons.value.map((button: KbButton) => ({
            ...button,
            type: value === 'reply' ? 'reply' : 'callback',
        })),
        timeout_seconds: value === 'reply' ? null : config.value.timeout_seconds ?? null,
    })
}
</script>

<template>
    <div class="accordion">
        <AccordionSection title="Type" default-open>
            <div class="config-field">
                <div class="field-label">Content type</div>
                <select
                    class="field-input"
                    :value="contentType"
                    @change="updateContentType(($event.target as HTMLSelectElement).value)"
                >
                    <option value="text">Text</option>
                    <option value="text_with_keyboard">Text with keyboard</option>
                    <option value="image">Image</option>
                    <option value="document">Document</option>
                    <option value="video">Video</option>
                    <option value="voice">Voice</option>
                </select>
            </div>

            <div v-if="showsButtons" class="config-field">
                <div class="field-label">Keyboard mode</div>
                <select
                    class="field-input"
                    :value="keyboardMode"
                    @change="updateKeyboardMode(($event.target as HTMLSelectElement).value)"
                >
                    <option value="inline">Inline</option>
                    <option value="reply">Reply</option>
                </select>
            </div>
        </AccordionSection>

        <AccordionSection v-if="showsButtons && !isReplyKeyboard" title="Behaviour">
            <div class="config-field">
                <label class="toggle-row">
                    <span class="field-label" style="margin:0">Remove keyboard after press</span>
                    <input
                        type="checkbox"
                        class="toggle-check"
                        :checked="(config.remove_keyboard_after_press as boolean) ?? true"
                        @change="update({ remove_keyboard_after_press: ($event.target as HTMLInputElement).checked })"
                    >
                </label>
                <p class="field-hint">
                    When off, the keyboard stays forever and button presses are handled even after the session ends.
                </p>
            </div>
            <div class="config-field">
                <div class="field-label">Timeout seconds</div>
                <input
                    class="field-input"
                    type="number"
                    min="1"
                    :value="(config.timeout_seconds as number | string | undefined) ?? ''"
                    placeholder="Optional"
                    @input="update({ timeout_seconds: ($event.target as HTMLInputElement).value === '' ? null : Number(($event.target as HTMLInputElement).value) })"
                >
            </div>
        </AccordionSection>

        <AccordionSection
            v-if="contentType === 'text' || contentType === 'text_with_keyboard'"
            title="Body"
        >
            <div class="config-field">
                <div class="field-label field-label--row">
                    Message text
                    <VariablePicker @select="insertText" />
                </div>
                <textarea
                    ref="textRef"
                    class="field-input"
                    rows="4"
                    :value="String(config.text ?? '')"
                    placeholder="Welcome, {{flow.name}}"
                    @input="update({ text: ($event.target as HTMLTextAreaElement).value })"
                    @drop="(v: DragEvent) => { const s = dropInsert(v, String(config.text ?? '')); if (s !== null) update({ text: s }) }"
                />
            </div>
        </AccordionSection>

        <AccordionSection v-if="showsMediaUrl" title="Media">
            <div class="config-field">
                <div class="field-label">File</div>
                <MediaPicker
                    :value="mediaFile"
                    :kind="mediaPickerKind"
                    @update:value="selectMediaFile"
                />
            </div>

            <div v-if="showsCaption" class="config-field">
                <div class="field-label field-label--row">
                    Caption
                    <VariablePicker @select="insertCaption" />
                </div>
                <textarea
                    ref="captionRef"
                    class="field-input"
                    rows="3"
                    :value="String(config.caption ?? '')"
                    placeholder="Optional caption"
                    @input="update({ caption: ($event.target as HTMLTextAreaElement).value })"
                    @drop="(v: DragEvent) => { const s = dropInsert(v, String(config.caption ?? '')); if (s !== null) update({ caption: s }) }"
                />
            </div>
        </AccordionSection>

        <AccordionSection
            v-if="showsButtons"
            title="Buttons"
            :badge="isDynamic ? 'dynamic' : (buttons.length > 0 ? buttons.length : null)"
        >
            <!-- Static / Dynamic toggle (inline keyboards only) -->
            <div v-if="!isReplyKeyboard" class="config-field">
                <div class="field-label">Keyboard type</div>
                <div class="kb-type-toggle">
                    <button
                        class="kb-type-btn"
                        :class="{ 'kb-type-btn--active': !isDynamic }"
                        type="button"
                        @click="switchKeyboardType('static')"
                    >Static</button>
                    <button
                        class="kb-type-btn"
                        :class="{ 'kb-type-btn--active': isDynamic, 'kb-type-btn--disabled': dynamicSwitchBlocked }"
                        :disabled="dynamicSwitchBlocked"
                        :title="dynamicSwitchBlocked ? 'Remove all button connections in the graph before switching to Dynamic' : undefined"
                        type="button"
                        @click="switchKeyboardType('dynamic')"
                    >Dynamic</button>
                </div>
                <p v-if="isDynamic" class="field-hint">
                    ⚙️ <em>Advanced / Developer feature.</em> Buttons are generated at runtime from a state
                    collection. Requires an upstream node (e.g. Call) that populates the source path with
                    objects containing a <em>label</em> field.
                </p>
            </div>

            <!-- Static keyboard -->
            <template v-if="!isDynamic">
                <KeyboardListEditor
                    :buttons="buttons"
                    :is-reply-keyboard="isReplyKeyboard"
                    :save-to-type="saveToType"
                    @update="update({ buttons: $event })"
                />

                <template v-if="!isReplyKeyboard && buttons.length > 0">
                    <div class="config-field">
                        <label class="save-answer-toggle">
                            <input
                                type="checkbox"
                                class="toggle-check"
                                :checked="saveAnswerEnabled"
                                @change="onSaveAnswerToggle(($event.target as HTMLInputElement).checked)"
                            >
                            <span class="save-answer-label">Save answer to variable</span>
                        </label>
                        <VariableStorageEditor
                            v-if="saveAnswerEnabled"
                            :model-value="answerVariable"
                            :type-options="BUTTON_VALUE_TYPES"
                            :known-groups="knownGroups"
                            show-storage
                            show-group
                            @update:model-value="onAnswerVariableUpdate"
                        />
                    </div>
                </template>
            </template>

            <!-- Dynamic keyboard -->
            <template v-else>
                <div class="config-field">
                    <div class="field-label">Source collection</div>
                    <StatePathPicker
                        :model-value="dynamicSource"
                        :allow-manual="false"
                        placeholder="flow.employees"
                        @update:model-value="updateDynamicSource"
                    />
                    <p class="field-hint">
                        Choose a variable registered by an upstream node (e.g. Call → Save response to).
                    </p>
                </div>

                <div class="config-field">
                    <div class="field-label">Buttons per row</div>
                    <input
                        class="field-input"
                        type="number"
                        min="1"
                        max="8"
                        :value="(dynamicConfig.max_per_row as number | undefined) ?? 2"
                        @input="updateDynamicMaxPerRow(($event.target as HTMLInputElement).value)"
                    >
                </div>

                <div class="config-field">
                    <div class="field-label">Save selected item to</div>
                    <VariableStorageEditor
                        :model-value="dynamicItemVariable"
                        :type-options="DYNAMIC_ITEM_TYPES"
                        :known-groups="knownGroups"
                        show-storage
                        show-group
                        @update:model-value="onDynamicItemVariableUpdate"
                    />
                    <p class="field-hint">
                        Full item object is saved here when a button is pressed.
                    </p>
                </div>
            </template>
        </AccordionSection>
    </div>
</template>

<style scoped>
.field-label--row {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.field-hint {
    font-weight: 400;
    font-size: 10px;
    color: var(--text-3);
    margin-left: 4px;
}
.field-hint em { font-style: normal; color: var(--primary); }

.toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    gap: 8px;
}
.toggle-check {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    cursor: pointer;
    accent-color: var(--primary);
}

.save-to-row {
    display: flex;
    gap: 6px;
}
.save-to-type {
    width: 90px;
    flex-shrink: 0;
}

.save-answer-toggle {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    margin-bottom: 8px;
}
.save-answer-label {
    font-size: 12.5px;
    color: var(--text-2);
}

.kb-type-toggle {
    display: flex;
    gap: 0;
    border: 1px solid var(--border);
    border-radius: 6px;
    overflow: hidden;
}
.kb-type-btn {
    flex: 1;
    padding: 5px 0;
    font-size: 12px;
    background: transparent;
    color: var(--text-2);
    border: none;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
}
.kb-type-btn:hover {
    background: var(--bg-2);
}
.kb-type-btn--active {
    background: var(--primary);
    color: #fff;
}
.kb-type-btn--disabled {
    opacity: 0.4;
    cursor: not-allowed;
}
</style>
