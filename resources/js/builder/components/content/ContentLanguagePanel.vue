<script setup lang="ts">
interface Language { code: string; name: string }

defineProps({
    languages: { type: Array as () => Language[], default: () => [] },
})

const emit = defineEmits(['addLanguage'])

const FLAG_MAP: Record<string, string> = {
    uk: '🇺🇦', en: '🇬🇧', ru: '🇷🇺', pl: '🇵🇱',
    de: '🇩🇪', fr: '🇫🇷', es: '🇪🇸', it: '🇮🇹',
}

function flag(code: string) {
    return FLAG_MAP[code] ?? '🌐'
}
</script>

<template>
    <div class="lang-panel">
        <div class="panel-header">Languages</div>
        <div class="panel-body">
            <div
                v-for="lang in languages"
                :key="(lang as Language).code"
                class="lang-item"
            >
                <div class="lang-dot"></div>
                <span>{{ flag((lang as Language).code) }} {{ (lang as Language).name }}</span>
            </div>
            <div class="add-wrap">
                <button type="button" class="add-btn" @click="emit('addLanguage')">
                    + Add language
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.lang-panel {
    width: 180px;
    border-left: 1px solid var(--border);
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
}
.panel-body { flex: 1; overflow-y: auto; padding: 6px 0; }
.lang-item {
    padding: 7px 12px;
    margin: 1px 6px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12.5px;
    color: var(--text-2);
}
.lang-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--sage); flex-shrink: 0; }
.add-wrap { padding: 8px 12px; margin-top: 4px; }
.add-btn {
    width: 100%; padding: 5px;
    border: 1px dashed var(--border-2);
    border-radius: 6px; background: transparent;
    font-family: var(--font-sans);
    font-size: 12px; color: var(--text-3);
    cursor: pointer; transition: all .15s;
}
.add-btn:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-bg); }
</style>
