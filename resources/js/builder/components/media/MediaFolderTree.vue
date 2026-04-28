<script setup>
defineProps({
    subfolders: { type: Array, default: () => [] },
    kindFilter: { type: String, default: null },
})

const emit = defineEmits(['navigate'])
</script>

<template>
    <div v-if="subfolders.length > 0" class="mft-root">
        <button
            v-for="folder in subfolders"
            :key="folder.id"
            type="button"
            class="mft-folder"
            @click="emit('navigate', folder.id)"
        >
            <svg class="mft-icon" viewBox="0 0 24 24" fill="currentColor">
                <path d="M19.5 21a3 3 0 003-3v-4.5a3 3 0 00-3-3h-15a3 3 0 00-3 3V18a3 3 0 003 3h15zM1.5 10.146V6a3 3 0 013-3h5.379a2.25 2.25 0 011.59.659l2.122 2.121c.14.141.331.22.53.22H19.5a3 3 0 013 3v1.146A4.483 4.483 0 0019.5 9h-15a4.483 4.483 0 00-3 1.146z" />
            </svg>
            <span class="mft-name">{{ folder.name }}</span>
            <span class="mft-count">
                {{ kindFilter ? folder.file_count_filtered : folder.file_count_total }}
            </span>
        </button>
    </div>
</template>

<style scoped>
.mft-root {
    display: flex;
    flex-direction: column;
    gap: 1px;
}

.mft-folder {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 6px 8px;
    border: none;
    background: transparent;
    border-radius: 6px;
    cursor: pointer;
    transition: background .12s;
    text-align: left;
    width: 100%;
}
.mft-folder:hover { background: var(--surface-2, #f4f5f6); }

.mft-icon {
    width: 15px;
    height: 15px;
    color: var(--amber, #d97706);
    flex-shrink: 0;
}

.mft-name {
    font-size: 12px;
    font-weight: 500;
    color: var(--text);
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.mft-count {
    font-size: 11px;
    color: var(--text-3);
    flex-shrink: 0;
}
</style>
