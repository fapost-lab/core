<script setup>
import { ref } from 'vue'
import { useMediaPickerStore } from '@builder/store/mediaPickerStore'
import { uploadMediaFile } from '@builder/composables/useMediaApi'
import MediaProviderWarning from './MediaProviderWarning.vue'

const props = defineProps({
    folderId: { type: String, default: null },
})

const emit = defineEmits(['uploaded'])

const store = useMediaPickerStore()

const dragging = ref(false)
const inputRef = ref(null)
const warnings = ref([])

function handleDragOver(e) {
    e.preventDefault()
    dragging.value = true
}

function handleDragLeave() {
    dragging.value = false
}

function handleDrop(e) {
    e.preventDefault()
    dragging.value = false
    const files = Array.from(e.dataTransfer?.files ?? [])
    uploadFiles(files)
}

function openPicker() {
    inputRef.value?.click()
}

function handleInputChange(e) {
    const files = Array.from(e.target.files ?? [])
    uploadFiles(files)
    e.target.value = ''
}

/**
 * @param {File[]} files
 */
async function uploadFiles(files) {
    warnings.value = []

    for (const file of files) {
        const entryId = crypto.randomUUID ? crypto.randomUUID() : Math.random().toString(36).slice(2)
        store.addUpload(entryId, file.name)

        try {
            const result = await uploadMediaFile(
                file,
                props.folderId,
                (pct) => store.setUploadProgress(entryId, pct),
            )

            store.markUploadDone(entryId)

            const fileData = result.data ?? result
            store.prependFile(fileData)
            emit('uploaded', fileData)

            if (Array.isArray(result.provider_warnings) && result.provider_warnings.length > 0) {
                warnings.value.push(...result.provider_warnings)
            }

            setTimeout(() => store.removeUpload(entryId), 1500)
        } catch (err) {
            const message = err?.body?.message ?? err?.message ?? 'Upload failed'
            store.markUploadError(entryId, message)
        }
    }
}
</script>

<template>
    <div class="mudz-root">
        <MediaProviderWarning
            v-if="warnings.length > 0"
            :warnings="warnings"
            @dismiss="warnings = []"
        />

        <div
            class="mudz-zone"
            :class="{ 'mudz-zone--drag': dragging }"
            @dragover="handleDragOver"
            @dragleave="handleDragLeave"
            @drop="handleDrop"
            @click="openPicker"
        >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
            </svg>
            <span>Drop files or <strong>click to upload</strong></span>
            <input ref="inputRef" type="file" multiple class="mudz-input" @change="handleInputChange" />
        </div>

        <div v-if="store.uploads.length > 0" class="mudz-progress-list">
            <div
                v-for="entry in store.uploads"
                :key="entry.id"
                class="mudz-progress-item"
                :class="`mudz-progress-item--${entry.status}`"
            >
                <span class="mudz-progress-name">{{ entry.name }}</span>

                <div class="mudz-progress-bar-wrap">
                    <div
                        class="mudz-progress-bar"
                        :style="{ width: entry.progress + '%' }"
                    />
                </div>

                <span class="mudz-progress-label">
                    <template v-if="entry.status === 'error'">{{ entry.error }}</template>
                    <template v-else-if="entry.status === 'done'">Done</template>
                    <template v-else>{{ entry.progress }}%</template>
                </span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.mudz-root {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.mudz-zone {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 10px 16px;
    border: 1.5px dashed var(--border);
    border-radius: 8px;
    background: var(--surface-2, #f8f9fa);
    cursor: pointer;
    font-size: 12px;
    color: var(--text-3);
    transition: border-color .12s, background .12s, color .12s;
    user-select: none;
}
.mudz-zone:hover,
.mudz-zone--drag {
    border-color: var(--primary);
    background: var(--primary-bg, #eef2ee);
    color: var(--text-2);
}
.mudz-zone strong { font-weight: 600; color: var(--primary); }
.mudz-zone svg { color: var(--text-3); flex-shrink: 0; }
.mudz-zone--drag svg { color: var(--primary); }

.mudz-input {
    display: none;
}

.mudz-progress-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.mudz-progress-item {
    display: grid;
    grid-template-columns: 1fr auto;
    grid-template-rows: auto auto;
    gap: 2px 8px;
    font-size: 11px;
}

.mudz-progress-name {
    grid-column: 1;
    color: var(--text-2);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.mudz-progress-label {
    grid-column: 2;
    grid-row: 1;
    color: var(--text-3);
    white-space: nowrap;
}
.mudz-progress-item--error .mudz-progress-label { color: var(--rose, #e53e3e); }
.mudz-progress-item--done .mudz-progress-label { color: var(--success, #38a169); }

.mudz-progress-bar-wrap {
    grid-column: 1 / -1;
    height: 3px;
    background: var(--border);
    border-radius: 2px;
    overflow: hidden;
}

.mudz-progress-bar {
    height: 100%;
    background: var(--primary);
    border-radius: 2px;
    transition: width .15s;
}
.mudz-progress-item--error .mudz-progress-bar { background: var(--rose, #e53e3e); }
.mudz-progress-item--done .mudz-progress-bar { background: var(--success, #38a169); }
</style>
