<script setup>
import { ref, watch } from 'vue'
import BaseModal from '@builder/components/editor/config/overrides/BaseModal.vue'
import MediaBreadcrumbs from './MediaBreadcrumbs.vue'
import MediaFolderTree from './MediaFolderTree.vue'
import MediaFileGrid from './MediaFileGrid.vue'
import MediaUploadDropZone from './MediaUploadDropZone.vue'
import { useMediaPickerStore } from '@builder/store/mediaPickerStore'

const props = defineProps({
    open: { type: Boolean, required: true },
    /** Optional kind filter: 'image'|'video'|'audio'|'document'|'sticker'|null */
    kind: { type: String, default: null },
    selectedFileId: { type: String, default: null },
})

const emit = defineEmits(['close', 'select'])

const store = useMediaPickerStore()
const localSelectedId = ref(props.selectedFileId)

watch(() => props.open, async (val) => {
    if (val) {
        store.reset(props.kind)
        localSelectedId.value = props.selectedFileId
        await store.navigate(null)
    }
})

function navigate(folderId) {
    store.navigate(folderId)
}

function selectFile(file) {
    localSelectedId.value = file.id
    emit('select', {
        id: file.id,
        name: file.name,
        kind: file.kind,
        preview_url: file.preview_url ?? file.preview?.signed_url ?? null,
    })
    emit('close')
}

const kindLabels = {
    image:    'Images',
    video:    'Videos',
    audio:    'Audio',
    document: 'Documents',
    sticker:  'Stickers',
}
</script>

<template>
    <BaseModal :open="open" width="860px" @close="emit('close')">
        <template #default>
            <div class="mpm-shell">
                <!-- Header -->
                <div class="mpm-header">
                    <div class="mpm-header-left">
                        <span class="mpm-title">Select media</span>
                        <span v-if="kind" class="mpm-kind-badge">{{ kindLabels[kind] ?? kind }}</span>
                    </div>
                    <button type="button" class="mpm-close" @click="emit('close')">×</button>
                </div>

                <!-- Breadcrumbs bar -->
                <div class="mpm-crumbs-bar">
                    <MediaBreadcrumbs
                        :breadcrumbs="store.breadcrumbs"
                        :current-folder-id="store.currentFolderId"
                        @navigate="navigate"
                    />
                </div>

                <!-- Error -->
                <div v-if="store.error" class="mpm-error">
                    {{ store.error }}
                </div>

                <!-- Body: sidebar + main -->
                <div class="mpm-body">
                    <!-- Sidebar: folders -->
                    <aside v-if="store.subfolders.length > 0" class="mpm-sidebar">
                        <div class="mpm-sidebar-label">Folders</div>
                        <MediaFolderTree
                            :subfolders="store.subfolders"
                            :kind-filter="store.kindFilter"
                            @navigate="navigate"
                        />
                    </aside>

                    <!-- Main: file grid -->
                    <main class="mpm-main">
                        <MediaFileGrid
                            :files="store.files"
                            :loading="store.loading"
                            :selected-file-id="localSelectedId"
                            @select="selectFile"
                        />
                    </main>
                </div>

                <!-- Footer: upload -->
                <div class="mpm-footer">
                    <MediaUploadDropZone
                        :folder-id="store.currentFolderId"
                    />
                </div>
            </div>
        </template>
    </BaseModal>
</template>

<style scoped>
.mpm-shell {
    display: flex;
    flex-direction: column;
    height: 70vh;
    max-height: 680px;
    /* override BaseModal bm-body padding */
    margin: -16px;
}

.mpm-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}

.mpm-header-left {
    display: flex;
    align-items: center;
    gap: 8px;
}

.mpm-title {
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    text-transform: uppercase;
    letter-spacing: .04em;
}

.mpm-kind-badge {
    font-size: 11px;
    font-weight: 500;
    padding: 2px 8px;
    border-radius: 20px;
    background: var(--primary-bg, #eef2ee);
    color: var(--primary);
    border: 1px solid color-mix(in srgb, var(--primary) 25%, transparent);
}

.mpm-close {
    width: 26px;
    height: 26px;
    border: none;
    background: transparent;
    color: var(--text-3);
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color .12s, background .12s;
}
.mpm-close:hover { color: var(--text); background: var(--surface-2); }

.mpm-crumbs-bar {
    padding: 6px 14px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
    background: var(--surface-2, #f8f9fa);
}

.mpm-error {
    padding: 8px 14px;
    font-size: 12px;
    color: var(--rose, #e53e3e);
    background: color-mix(in srgb, var(--rose, #e53e3e) 8%, transparent);
    border-bottom: 1px solid color-mix(in srgb, var(--rose, #e53e3e) 20%, transparent);
    flex-shrink: 0;
}

.mpm-body {
    display: flex;
    flex: 1;
    overflow: hidden;
    min-height: 0;
}

.mpm-sidebar {
    width: 180px;
    flex-shrink: 0;
    border-right: 1px solid var(--border);
    overflow-y: auto;
    padding: 10px 8px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.mpm-sidebar-label {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: var(--text-3);
    padding: 0 4px;
    margin-bottom: 2px;
}

.mpm-main {
    flex: 1;
    overflow-y: auto;
    padding: 12px;
    display: flex;
    flex-direction: column;
    min-height: 0;
}

.mpm-footer {
    border-top: 1px solid var(--border);
    padding: 10px 14px;
    flex-shrink: 0;
    background: var(--surface-2, #f8f9fa);
}
</style>
