<script setup>
import { computed } from 'vue'
import { useBuilderStore } from '@builder/store/builderStore'

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
const isReplyKeyboard = computed(() => showsButtons.value && keyboardMode.value === 'reply')

function update(patch) {
    emit('update:config', patch)
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
        builderStore.definition.edges = builderStore.definition.edges.filter(
            (edge) => edge.from !== props.node.id || (edge.handle ?? 'default') !== 'default',
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

function updateButton(index, patch) {
    const nextButtons = [...buttons.value]
    nextButtons[index] = { ...nextButtons[index], ...patch }
    update({ buttons: nextButtons })
}

function addButton() {
    update({
        buttons: [
            ...buttons.value,
            {
                id: crypto.randomUUID(),
                type: keyboardMode.value === 'reply' ? 'reply' : 'callback',
                label: '',
                value: '',
                order: buttons.value.length,
                row: 0,
            },
        ],
    })
}

function removeButton(index) {
    update({
        buttons: buttons.value.filter((_, currentIndex) => currentIndex !== index),
    })
}
</script>

<template>
    <div>
        <div class="config-section">
            <div class="config-label">Type</div>
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
        </div>

        <div v-if="contentType === 'text' || contentType === 'text_with_keyboard'" class="config-section">
            <div class="config-label">Body</div>
            <div class="config-field">
                <div class="field-label">Message text</div>
                <textarea
                    class="field-input"
                    rows="4"
                    :value="config.text ?? ''"
                    placeholder="Welcome, {{flow.name}}"
                    @input="update({ text: $event.target.value })"
                />
            </div>
        </div>

        <div v-if="showsMediaUrl" class="config-section">
            <div class="config-label">Media</div>
            <div class="config-field">
                <div class="field-label">Media URL</div>
                <input
                    class="field-input"
                    type="text"
                    :value="config.media_url ?? ''"
                    placeholder="https://..."
                    @input="update({ media_url: $event.target.value })"
                >
            </div>

            <div v-if="showsCaption" class="config-field">
                <div class="field-label">Caption</div>
                <textarea
                    class="field-input"
                    rows="3"
                    :value="config.caption ?? ''"
                    placeholder="Optional caption"
                    @input="update({ caption: $event.target.value })"
                />
            </div>
        </div>

        <div v-if="showsButtons" class="config-section">
            <div class="config-label">Buttons</div>
            <div
                v-for="(btn, index) in buttons"
                :key="btn.id ?? index"
                class="repeater-item"
            >
                <input
                    class="field-input"
                    :value="btn.label ?? ''"
                    placeholder="Label"
                    @input="updateButton(index, { label: $event.target.value })"
                >
                <input
                    v-if="!isReplyKeyboard"
                    class="field-input"
                    :value="btn.value ?? ''"
                    placeholder="Value"
                    @input="updateButton(index, { value: $event.target.value })"
                >
                <input
                    class="field-input field-small"
                    type="number"
                    min="0"
                    :value="btn.row ?? 0"
                    placeholder="Row"
                    @input="updateButton(index, { row: Number($event.target.value) || 0 })"
                >
                <input
                    class="field-input field-small"
                    type="number"
                    min="0"
                    :value="btn.order ?? 0"
                    placeholder="Order"
                    @input="updateButton(index, { order: Number($event.target.value) || 0 })"
                >
                <button class="rep-del" type="button" @click="removeButton(index)">×</button>
            </div>
            <button class="add-item-btn" style="margin-top:4px" type="button" @click="addButton">
                + Add button
            </button>
        </div>

        <div v-if="showsButtons && !isReplyKeyboard" class="config-section">
            <div class="config-label">Waiting</div>
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
        </div>
    </div>
</template>

<style scoped>
.config-section { padding: 12px 0; border-bottom: 1px solid var(--border); }
.config-section:last-child { border-bottom: none; }
.config-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--text-3);
    margin-bottom: 7px;
}
.config-field { margin-bottom: 10px; }
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
    resize: none;
}
.repeater-item {
    display: grid;
    grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr) 72px 72px 28px;
    gap: 6px;
    margin-bottom: 6px;
    align-items: center;
}
.field-small { text-align: center; }
.rep-del {
    background: transparent;
    border: none;
    color: var(--text-3);
    cursor: pointer;
    font-size: 16px;
}
.add-item-btn {
    width: 100%;
    padding: 5px;
    border: 1px dashed var(--border-2);
    border-radius: 6px;
    background: transparent;
    font-family: 'DM Sans', sans-serif;
    font-size: 12px;
    color: var(--text-3);
    cursor: pointer;
}
</style>
