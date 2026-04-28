<script setup>
import { computed } from 'vue'
import { useBuilderStore } from '@builder/store/builderStore'
import KeyboardListEditor from './KeyboardListEditor.vue'
import VariablePicker from '../VariablePicker.vue'
import AccordionSection from '../AccordionSection.vue'
import MediaPicker from '@builder/components/media/MediaPicker.vue'

const props = defineProps({
    node:   { type: Object, required: true },
    schema: { type: Object, required: true },
})

const emit = defineEmits(['update:config'])
const builderStore = useBuilderStore()

const config = computed(() => props.node.config ?? {})
const buttons = computed(() => config.value.buttons ?? [])
const contentType = computed(() => config.value.content_type ?? 'text')
const keyboardMode = computed(() => config.value.keyboard_mode ?? 'inline')
const showsButtons = computed(() => contentType.value === 'text_with_keyboard')
const showsMediaUrl = computed(() => ['image', 'document', 'video', 'voice'].includes(contentType.value))
const showsCaption = computed(() => ['image', 'document', 'video'].includes(contentType.value))
const mediaPickerKind = computed(() => {
    const map = { image: 'image', video: 'video', document: 'document', voice: 'audio' }
    return map[contentType.value] ?? null
})
const mediaFile = computed(() => config.value.media_file ?? null)

/** @param {{ id: string, name: string, kind: string, preview_url: string|null }|null} file */
function selectMediaFile(file) {
    if (file) {
        update({ media_file: file, media_url: file.preview_url ?? '' })
    } else {
        update({ media_file: null, media_url: '' })
    }
}
const isReplyKeyboard = computed(() => showsButtons.value && keyboardMode.value === 'reply')
const saveToType = computed(() => config.value.save_to_type ?? 'string')

function update(patch) {
    emit('update:config', patch)
}

function dropInsert(e, currentValue) {
    e.preventDefault()
    const snippet = e.dataTransfer.getData('text/plain')
    if (!snippet) return null
    const el = e.target
    const at = el.selectionStart ?? currentValue.length
    return currentValue.slice(0, at) + snippet + currentValue.slice(el.selectionEnd ?? at)
}

function updateContentType(value) {
    const patch = { content_type: value }

    if (value !== 'text_with_keyboard') {
        patch.keyboard_mode = null
        patch.buttons = []
        patch.timeout_seconds = null
    } else {
        patch.keyboard_mode = config.value.keyboard_mode ?? 'inline'
    }

    update(patch)
}

function updateKeyboardMode(value) {
    if (value === 'reply') {
        // Reply keyboard is terminal — pressing a button triggers a separate
        // flow via keyword/trigger matching, never returns to this session.
        // Drop ALL outgoing edges (default + per-button callback handles).
        builderStore.definition.edges = builderStore.definition.edges.filter(
            (edge) => edge.from !== props.node.id,
        )
    }

    update({
        keyboard_mode: value,
        buttons: buttons.value.map((button) => ({
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
                    @change="updateContentType($event.target.value)"
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
                    @change="updateKeyboardMode($event.target.value)"
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
                        :checked="config.remove_keyboard_after_press ?? true"
                        @change="update({ remove_keyboard_after_press: $event.target.checked })"
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
                    :value="config.timeout_seconds ?? ''"
                    placeholder="Optional"
                    @input="update({ timeout_seconds: $event.target.value === '' ? null : Number($event.target.value) })"
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
                    :value="config.text ?? ''"
                    placeholder="Welcome, {{flow.name}}"
                    @input="update({ text: $event.target.value })"
                    @drop="v => { const s = dropInsert(v, config.text ?? ''); if (s !== null) update({ text: s }) }"
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

            <div class="config-field">
                <div class="field-label">or URL</div>
                <input
                    class="field-input"
                    type="text"
                    :value="mediaFile ? '' : (config.media_url ?? '')"
                    :disabled="!!mediaFile"
                    placeholder="https://..."
                    @input="update({ media_url: $event.target.value, media_file: null })"
                >
            </div>

            <div v-if="showsCaption" class="config-field">
                <div class="field-label field-label--row">
                    Caption
                    <VariablePicker />
                </div>
                <textarea
                    class="field-input"
                    rows="3"
                    :value="config.caption ?? ''"
                    placeholder="Optional caption"
                    @input="update({ caption: $event.target.value })"
                    @drop="v => { const s = dropInsert(v, config.caption ?? ''); if (s !== null) update({ caption: s }) }"
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
                            @change="update({ save_to_type: $event.target.value })"
                        >
                            <option value="string">String</option>
                            <option value="number">Number</option>
                            <option value="boolean">Boolean</option>
                        </select>
                        <input
                            class="field-input"
                            :value="config.save_to ?? ''"
                            placeholder="e.g. menu_choice"
                            @input="update({ save_to: $event.target.value || null })"
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
