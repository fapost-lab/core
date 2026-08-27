<script setup lang="ts">
import ContentKeyItem from './ContentKeyItem.vue'

interface ContentKeyEntry { key: string; translationCount?: number }

defineProps({
    keys:        { type: Array as () => ContentKeyEntry[], default: () => [] },
    selectedKey: { type: String, default: null },
    languages:   { type: Array,  default: () => [] },
})

const emit = defineEmits(['select', 'addKey'])
</script>

<template>
    <div class="keys-panel">
        <div class="panel-header">
            Content keys
            <span class="header-action" @click="emit('addKey')">+ New</span>
        </div>
        <div class="panel-body">
            <div v-if="keys.length === 0" class="empty">No content keys yet</div>
            <ContentKeyItem
                v-for="item in keys"
                :key="item.key"
                :content-key="item.key"
                :translation-count="item.translationCount ?? 0"
                :total-languages="languages.length"
                :is-selected="selectedKey === item.key"
                @select="emit('select', $event)"
            />
        </div>
    </div>
</template>

<style scoped>
.keys-panel {
    width: 220px;
    border-right: 1px solid var(--border);
    background: var(--surface);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    flex-shrink: 0;
}
.panel-header {
    padding: 10px 14px 9px;
    border-bottom: 1px solid var(--border);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .05em;
    text-transform: uppercase;
    color: var(--text-3);
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.header-action {
    font-size: 11px;
    font-weight: 400;
    text-transform: none;
    letter-spacing: 0;
    color: var(--text-3);
    cursor: pointer;
    transition: color .12s;
}
.header-action:hover { color: var(--text-2); }
.panel-body { flex: 1; overflow-y: auto; padding: 6px 0; }
.empty { font-size: 12px; color: var(--text-3); text-align: center; padding: 24px 0; }
</style>
