<script setup lang="ts">
import MediaFileCard from './MediaFileCard.vue'

interface MediaFile {
    id: string
    name: string
    [key: string]: unknown
}

defineProps({
    files: { type: Array as () => MediaFile[], default: () => [] },
    loading: { type: Boolean, default: false },
    selectedFileId: { type: String as () => string | null, default: null },
})

const emit = defineEmits(['select'])
</script>

<template>
    <div class="mfg-root">
        <div v-if="loading" class="mfg-state">
            <svg class="mfg-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" />
            </svg>
        </div>

        <div v-else-if="files.length === 0" class="mfg-state mfg-empty">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
            </svg>
            <span>No files here</span>
        </div>

        <div v-else class="mfg-grid">
            <MediaFileCard
                v-for="file in files"
                :key="file.id"
                :file="file"
                :selected="file.id === selectedFileId"
                @select="emit('select', $event)"
            />
        </div>
    </div>
</template>

<style scoped>
.mfg-root {
    flex: 1;
    overflow-y: auto;
    min-height: 0;
}

.mfg-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    height: 100%;
    min-height: 120px;
    color: var(--text-3);
    font-size: 12px;
}

.mfg-empty svg { opacity: .5; }

.mfg-spin {
    width: 24px;
    height: 24px;
    animation: mfg-rotate 1s linear infinite;
}

@keyframes mfg-rotate {
    to { transform: rotate(360deg); }
}

.mfg-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
    gap: 8px;
    padding: 2px;
}
</style>
