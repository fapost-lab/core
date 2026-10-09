<script setup lang="ts">
import {computed} from 'vue'

interface Language { code: string; name: string }

const props = defineProps({
    contentKey:   { type: String, default: null },
    translations: { type: Object as () => Record<string, string>, default: () => ({}) },
    languages:    { type: Array as () => Language[], default: () => [] },
})

const emit = defineEmits(['update', 'save'])

const FLAG_MAP: Record<string, string> = {
    uk: '🇺🇦', en: '🇬🇧', ru: '🇷🇺', pl: '🇵🇱',
    de: '🇩🇪', fr: '🇫🇷', es: '🇪🇸', it: '🇮🇹',
}

function flag(code: string) { return FLAG_MAP[code] ?? '🌐' }

function onInput(langCode: string, value: string) {
    emit('update', props.contentKey, langCode, value)
}

function onTextareaInput(langCode: string, e: Event) {
    onInput(langCode, (e.target as HTMLTextAreaElement).value)
}

const translatedCount = computed(() =>
    props.languages.filter((l: Language) => props.translations[l.code]?.trim()).length,
)
</script>

<template>
    <div class="editor-panel">
        <!-- Empty state -->
        <div v-if="!contentKey" class="empty">
            Select a content key to edit translations
        </div>

        <div v-else class="content-editor-wrap">
            <div class="content-editor-card">
                <div class="content-editor-header">
                    <div style="flex:1;min-width:0">
                        <div class="content-editor-title">
                            {{ contentKey }}
                            <span class="content-editor-meta">· {{ translatedCount }}/{{ languages.length }} translations</span>
                        </div>
                    </div>
                    <button
                        class="card-header-btn card-header-btn--primary"
                        type="button"
                        @click="emit('save')"
                    >Save
                    </button>
                </div>
                <div class="content-editor-body">
                    <div
                        v-for="lang in languages"
                        :key="(lang as Language).code"
                        class="lang-field"
                    >
                        <div class="lang-label">
                            <span class="lang-flag">{{ flag((lang as Language).code) }}</span>
                            {{ (lang as Language).name }}
                            <span class="char-count">
                                {{ (translations[(lang as Language).code] ?? '').length }}
                            </span>
                        </div>
                        <textarea
                            :value="translations[(lang as Language).code] ?? ''"
                            class="lang-textarea"
                            rows="3"
                            @input="onTextareaInput((lang as Language).code, $event)"
                        />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.editor-panel {
    flex: 1;
    background: var(--bg);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-width: 0;
}

.card-header-btn {
    padding: 3px 10px;
    border-radius: 5px;
    font-family: var(--font-sans);
    font-size: 11px;
    font-weight: 500;
    cursor: pointer;
    background: color-mix(in srgb, var(--band-fg) 14%, transparent);
    color: color-mix(in srgb, var(--band-fg) 90%, transparent);
    border: 1px solid color-mix(in srgb, var(--band-fg) 25%, transparent);
    transition: background .15s, color .15s;
}

.card-header-btn:hover {
    background: color-mix(in srgb, var(--band-fg) 24%, transparent);
    color: var(--band-fg);
}

.card-header-btn--primary {
    background: var(--band-fg);
    color: var(--band-btn-fg);
    border-color: var(--band-fg);
}

.card-header-btn--primary:hover {
    background: color-mix(in srgb, var(--band-fg) 90%, transparent);
    color: var(--band-btn-fg);
}

.empty {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12.5px;
    color: var(--text-3);
}

.content-editor-wrap {
    flex: 1;
    min-height: 0;
    padding: 10px;
    display: flex;
    flex-direction: column;
}

.content-editor-card {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid color-mix(in srgb, var(--primary) 34%, var(--border));
    border-radius: 8px;
    background: linear-gradient(180deg, color-mix(in srgb, var(--band-deep-2) 90%, transparent) 0%, color-mix(in srgb, var(--band-tint-2) 76%, transparent) 24%, color-mix(in srgb, var(--band-tint-2) 20%, transparent) 48%, color-mix(in srgb, var(--band-tint-2) 0%, transparent) 68%),
    var(--primary-bg);
    box-shadow: var(--shadow);
    padding: 7px;
}

.content-editor-header {
    min-height: 42px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 14px 8px;
    border: none;
    background: transparent;
    color: color-mix(in srgb, var(--band-fg) 90%, transparent);
    flex-shrink: 0;
}

.content-editor-title {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.2;
    letter-spacing: .04em;
    text-transform: uppercase;
    color: color-mix(in srgb, var(--band-fg) 90%, transparent);
}

.content-editor-meta {
    color: color-mix(in srgb, var(--band-fg) 68%, transparent);
    font-weight: 600;
}

.content-editor-card .card-header-btn {
    background: color-mix(in srgb, var(--band-fg) 14%, transparent);
    color: color-mix(in srgb, var(--band-fg) 90%, transparent);
    border-color: color-mix(in srgb, var(--band-fg) 25%, transparent);
}

.content-editor-card .card-header-btn:hover {
    background: color-mix(in srgb, var(--band-fg) 24%, transparent);
    color: var(--band-fg);
}

.content-editor-card .card-header-btn--primary {
    background: var(--band-fg);
    color: var(--band-btn-fg);
    border-color: var(--band-fg);
}

.content-editor-card .card-header-btn--primary:hover {
    background: color-mix(in srgb, var(--band-fg) 90%, transparent);
    color: var(--band-btn-fg);
}

.content-editor-body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    margin-top: 0;
    padding: 10px;
    background: linear-gradient(180deg, color-mix(in srgb, var(--paper) 96%, transparent), color-mix(in srgb, var(--paper) 90%, transparent)),
    var(--primary-bg);
    border: 1px dashed color-mix(in srgb, var(--primary) 44%, var(--paper));
    border-radius: 7px;
    box-shadow: inset 0 1px 0 color-mix(in srgb, var(--paper) 65%, transparent);
}

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
    font-family: var(--font-sans);
    font-size: 13px;
    color: var(--text);
    outline: none;
    resize: none;
    line-height: 1.6;
    transition: border-color .15s;
}
.lang-textarea:focus { border-color: var(--primary); }
</style>
