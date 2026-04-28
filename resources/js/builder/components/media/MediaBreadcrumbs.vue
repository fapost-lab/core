<script setup lang="ts">
interface Crumb { id: string; name: string }

defineProps({
    breadcrumbs: { type: Array as () => Crumb[], default: () => [] },
    currentFolderId: { type: String as () => string | null, default: null },
})

const emit = defineEmits(['navigate'])
</script>

<template>
    <nav class="mbc-nav">
        <button type="button" class="mbc-item mbc-root" @click="emit('navigate', null)">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 9.5L12 3l9 6.5V20a1 1 0 01-1 1H4a1 1 0 01-1-1V9.5z" />
                <path d="M9 21V12h6v9" />
            </svg>
            All files
        </button>

        <template v-for="crumb in breadcrumbs" :key="crumb.id">
            <span class="mbc-sep">/</span>
            <button
                type="button"
                class="mbc-item"
                :class="{ 'mbc-item--active': crumb.id === currentFolderId }"
                @click="emit('navigate', crumb.id)"
            >
                {{ crumb.name }}
            </button>
        </template>
    </nav>
</template>

<style scoped>
.mbc-nav {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 2px;
    min-height: 28px;
}

.mbc-item {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 7px;
    border: none;
    background: transparent;
    border-radius: 5px;
    font-size: 12px;
    font-weight: 500;
    color: var(--text-2);
    cursor: pointer;
    transition: background .12s, color .12s;
    white-space: nowrap;
}
.mbc-item:hover { background: var(--surface-2); color: var(--text); }
.mbc-item--active { color: var(--text); font-weight: 600; pointer-events: none; }

.mbc-root { color: var(--text-3); }
.mbc-root:hover { color: var(--text); }

.mbc-sep {
    color: var(--text-3);
    font-size: 12px;
    padding: 0 1px;
    user-select: none;
}
</style>
