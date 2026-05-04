<script setup lang="ts">
import {computed} from 'vue'
import {useBuilderStore} from '@builder/store/builderStore'
import KeyboardListEditor from './KeyboardListEditor.vue'
import VariablePicker from '../VariablePicker.vue'
import AccordionSection from '../AccordionSection.vue'
import MediaPicker from '@builder/components/media/MediaPicker.vue'

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
const saveToType = computed(() => String(config.value.save_to_type ?? 'string'))

function update(patch: unknown) {
    emit('update:config', patch)
}

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
                    <VariablePicker />
                </div>
                <textarea
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
                    <VariablePicker />
                </div>
                <textarea
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
            :badge="buttons.length > 0 ? buttons.length : null"
        >
            <KeyboardListEditor
                :buttons="buttons"
                :is-reply-keyboard="isReplyKeyboard"
                :save-to-type="saveToType"
                @update="update({ buttons: $event })"
            />

            <template v-if="!isReplyKeyboard && buttons.length > 0">
                <div class="config-field">
                    <div class="field-label">
                        Save answer to
                        <span class="field-hint">flow.<em>variable</em></span>
                    </div>
                    <div class="save-to-row">
                        <select
                            class="field-input save-to-type"
                            :value="saveToType"
                            @change="update({ save_to_type: ($event.target as HTMLSelectElement).value })"
                        >
                            <option value="string">String</option>
                            <option value="number">Number</option>
                            <option value="boolean">Boolean</option>
                        </select>
                        <input
                            class="field-input"
                            :value="config.save_to ?? ''"
                            placeholder="e.g. menu_choice"
                            @input="update({ save_to: ($event.target as HTMLInputElement).value || null })"
                        >
                    </div>
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
</style>
