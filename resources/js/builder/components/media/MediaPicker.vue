<script setup lang="ts">
import {ref, watch} from 'vue'
import MediaPickerModal from './MediaPickerModal.vue'
import {fetchMediaFile} from '@builder/composables/useMediaApi'

/**
 * @typedef {{
 *   id: string,
 *   name: string,
 *   kind: string,
 *   preview_url: string|null,
 * }} PickedFile
 */

const props = defineProps({
    /** Currently selected file, or null. */
    value: { type: Object as () => Record<string, unknown> | null, default: null },
    /** Optional kind filter passed to the modal. */
    kind: { type: String as () => string | null, default: null },
    placeholder: { type: String, default: 'Select a file…' },
})

const emit = defineEmits(['update:value'])

const modalOpen = ref(false)

function openModal() {
    modalOpen.value = true
}

interface PickedFile {
    id: string
    name: string
    kind: string
    preview_url: string | null
    [key: string]: unknown
}

function handleSelect(file: PickedFile) {
    emit('update:value', file)
    modalOpen.value = false
}

function clearSelection() {
    emit('update:value', null)
}

const isImage = (v: Record<string, unknown> | null | undefined) => v?.kind === 'image'

// Signed preview URLs persisted in node config expire over time. Keep a
// local fresh URL refreshed from the API and let it take precedence
// over the (possibly stale) one carried in `value.preview_url`.
const refreshedPreviewUrl = ref<string | null>(null)

async function refreshPreview(fileId: string | undefined | null) {
    refreshedPreviewUrl.value = null
    if (!fileId) return
    try {
        const file = await fetchMediaFile(fileId)
        refreshedPreviewUrl.value = file.preview_url
            ?? file.preview?.signed_url
            ?? null
    } catch {
        // Silent: the saved URL might still work for a moment, and the
        // node config is the source of truth for the file id.
    }
}

watch(
    () => (props.value?.id as string | undefined) ?? null,
    (id) => { refreshPreview(id) },
    { immediate: true },
)

function effectivePreviewUrl(): string | null {
    return refreshedPreviewUrl.value ?? (props.value?.preview_url as string | null | undefined) ?? null
}

function onImageError() {
    // Browser failed to load — usually the signed URL just expired.
    // Refetch metadata to get a fresh URL.
    refreshPreview(props.value?.id as string | undefined)
}
</script>

<template>
    <div class="mp-root">
        <!-- Current selection -->
        <div v-if="value" class="mp-selected">
            <div class="mp-selected-thumb">
                <img
                    v-if="isImage(value) && effectivePreviewUrl()"
                    :src="effectivePreviewUrl() as string"
                    :alt="value.name as string"
                    class="mp-selected-img"
                    @error="onImageError"
                />
                <svg
                    v-else
                    class="mp-selected-icon"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                >
                    <path d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
            </div>
            <span class="mp-selected-name" :title="value.name as string">{{ value.name }}</span>
            <div class="mp-selected-actions">
                <button type="button" class="mp-btn mp-btn--ghost" @click="openModal">Change</button>
                <button type="button" class="mp-btn mp-btn--ghost mp-btn--danger" @click="clearSelection">×</button>
            </div>
        </div>

        <!-- Empty state: open picker -->
        <button v-else type="button" class="mp-empty" @click="openModal">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
            </svg>
            {{ placeholder }}
        </button>

        <MediaPickerModal
            :open="modalOpen"
            :kind="kind"
            :selected-file-id="(value?.id as string | null) ?? null"
            @close="modalOpen = false"
            @select="handleSelect"
        />
    </div>
</template>

<style scoped>
.mp-root {
    width: 100%;
}

/* Empty / trigger button */
.mp-empty {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    padding: 7px 10px;
    background: var(--surface-2);
    border: 1.5px dashed var(--border);
    border-radius: 6px;
    font-size: 12px;
    color: var(--text-3);
    cursor: pointer;
    transition: border-color .12s, color .12s, background .12s;
}
.mp-empty:hover {
    border-color: var(--primary);
    color: var(--primary);
    background: var(--primary-bg);
}

/* Selected state */
.mp-selected {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 6px;
    min-width: 0;
}

.mp-selected-thumb {
    width: 36px;
    height: 36px;
    border-radius: 4px;
    overflow: hidden;
    background: var(--surface);
    border: 1px solid var(--border);
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.mp-selected-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.mp-selected-icon {
    width: 18px;
    height: 18px;
    color: var(--text-3);
}

.mp-selected-name {
    flex: 1;
    font-size: 12px;
    font-weight: 500;
    color: var(--text);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.mp-selected-actions {
    display: flex;
    gap: 4px;
    flex-shrink: 0;
}

.mp-btn {
    padding: 3px 8px;
    border: 1px solid var(--border);
    border-radius: 5px;
    font-size: 11px;
    font-weight: 500;
    background: var(--surface);
    color: var(--text-2);
    cursor: pointer;
    transition: background .12s, color .12s, border-color .12s;
}
.mp-btn:hover { background: var(--surface-2); color: var(--text); }

.mp-btn--ghost {
    background: transparent;
    border-color: transparent;
}
.mp-btn--ghost:hover { background: var(--surface-2); border-color: var(--border); }

.mp-btn--danger:hover { color: var(--rose); border-color: color-mix(in srgb, var(--rose) 30%, transparent); }
</style>
