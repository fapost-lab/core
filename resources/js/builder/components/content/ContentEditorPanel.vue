<script setup>
import { computed } from 'vue'

const props = defineProps({
    contentKey:   { type: String, default: null },
    translations: { type: Object, default: () => ({}) },
    languages:    { type: Array,  default: () => [] },
})

const emit = defineEmits(['update', 'save', 'delete'])

const FLAG_MAP = {
    uk: '🇺🇦', en: '🇬🇧', ru: '🇷🇺', pl: '🇵🇱',
    de: '🇩🇪', fr: '🇫🇷', es: '🇪🇸', it: '🇮🇹',
}

function flag(code) { return FLAG_MAP[code] ?? '🌐' }

function onInput(langCode, value) {
    emit('update', props.contentKey, langCode, value)
}

const translatedCount = computed(() =>
    props.languages.filter((l) => props.translations[l.code]?.trim()).length,
)
</script>

<template>
    <div class="editor-panel">
        <!-- Header -->
        <div class="panel-header">
            <span class="key-title">{{ contentKey ?? '—' }}</span>
            <div class="header-actions">
                <button
                    v-if="contentKey"
                    type="button"
                    class="btn btn-ghost"
                    @click="emit('delete', contentKey)"
                >
                    Delete
                </button>
                <button
                    v-if="contentKey"
                    type="button"
                    class="btn btn-primary"
                    @click="emit('save')"
                >
                    Save
                </button>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="!contentKey" class="empty">
            Select a content key to edit translations
        </div>

        <!-- Editor -->
        <div v-else class="editor-body">
            <div class="key-meta">
                {{ translatedCount }} / {{ languages.length }} translations
            </div>

            <div
                v-for="lang in languages"
                :key="lang.code"
                class="lang-field"
            >
                <div class="lang-label">
                    <span class="lang-flag">{{ flag(lang.code) }}</span>
                    {{ lang.name }}
                    <span class="char-count">
                        {{ (translations[lang.code] ?? '').length }}
                    </span>
                </div>
                <textarea
                    class="lang-textarea"
                    rows="3"
                    :value="translations[lang.code] ?? ''"
                    @input="onInput(lang.code, $event.target.value)"
                />
            </div>
        </div>
    </div>
</template>

<style scoped>
.editor-panel { flex: 1; background: var(--bg); display: flex; flex-direction: column; overflow: hidden; }

.panel-header {
    padding: 10px 20px 9px;
    border-bottom: 1px solid var(--border);
    background: var(--surface);
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
.key-title { font-family: 'DM Mono', monospace; font-weight: 500; font-size: 12px; text-transform: none; letter-spacing: 0; }
.header-actions { display: flex; gap: 6px; }
.btn {
    padding: 3px 10px;
    border-radius: var(--radius);
    font-family: 'DM Sans', sans-serif;
    font-size: 11.5px;
    font-weight: 500;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all .15s;
}
.btn-ghost   { background: transparent; color: var(--text-2); border-color: var(--border); }
.btn-ghost:hover { background: var(--surface-2); }
.btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
.btn-primary:hover { background: #4a5c48; }

.empty {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12.5px;
    color: var(--text-3);
}

.editor-body { flex: 1; overflow-y: auto; padding: 20px 24px; }
.key-meta { font-size: 12px; color: var(--text-3); margin-bottom: 20px; }

.lang-field { margin-bottom: 16px; }
.lang-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--text-3);
    margin-bottom: 5px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.lang-flag { font-size: 14px; }
.char-count { margin-left: auto; font-size: 10px; font-weight: 400; text-transform: none; letter-spacing: 0; }

.lang-textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface);
    font-family: 'DM Sans', sans-serif;
    font-size: 13px;
    color: var(--text);
    outline: none;
    resize: none;
    line-height: 1.6;
    transition: border-color .15s;
}
.lang-textarea:focus { border-color: var(--primary); }
</style>
