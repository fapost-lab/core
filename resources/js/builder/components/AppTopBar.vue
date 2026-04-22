<script setup>
import { useTranslations } from '../composables/useTranslations.js'

const { t } = useTranslations()

defineProps({
    flowName:         { type: String,  required: true },
    draftVersion:     { type: Number,  default: null },
    publishedVersion: { type: Number,  default: null },
    saveStatus:       { type: String,  default: 'idle' },
    activeTab:        { type: String,  default: 'builder' },
    backUrl:          { type: String,  default: null },
    canUndo:          { type: Boolean, default: false },
    canRedo:          { type: Boolean, default: false },
})

const emit = defineEmits([
    'tabChange', 'rollback', 'preview', 'validate', 'saveDraft', 'publish', 'undo', 'redo',
])

const SAVE_COLORS = {
    idle:     'var(--text-3)',
    saving:   'var(--text-3)',
    saved:    'var(--sage)',
    conflict: 'var(--amber)',
    error:    'var(--rose)',
}
</script>

<template>
    <div class="topbar">
        <!-- Left -->
        <div class="topbar-left">
            <a v-if="backUrl" :href="backUrl" class="btn-back" :title="t('topbar.back')">
                ←
            </a>
            <span class="flow-name">{{ flowName }}</span>
            <div class="version-badge">
                <span v-if="draftVersion != null" class="badge badge-draft">Draft v{{ draftVersion }}</span>
                <span v-if="publishedVersion != null" class="badge badge-pub">Published v{{ publishedVersion }}</span>
            </div>
            <span
                v-if="saveStatus !== 'idle'"
                class="save-status"
                :style="{ color: SAVE_COLORS[saveStatus] }"
            >
                {{ saveStatus === 'saving'   ? t('topbar.status_saving')   :
                   saveStatus === 'saved'    ? t('topbar.status_saved')    :
                   saveStatus === 'conflict' ? t('topbar.status_conflict') :
                                              t('topbar.status_error') }}
            </span>
        </div>

        <!-- Tabs -->
        <div class="topbar-tabs">
            <button
                class="tab-btn"
                :class="{ active: activeTab === 'builder' }"
                @click="emit('tabChange', 'builder')"
            >
                {{ t('topbar.tab_builder') }}
            </button>
            <button
                class="tab-btn"
                :class="{ active: activeTab === 'content' }"
                @click="emit('tabChange', 'content')"
            >
                {{ t('topbar.tab_content') }}
            </button>
        </div>

        <!-- Right -->
        <div class="topbar-right">
            <button class="btn btn-ghost" :disabled="!canUndo" @click="emit('undo')">↩ Undo</button>
            <button class="btn btn-ghost" :disabled="!canRedo" @click="emit('redo')">↪ Redo</button>
            <button class="btn btn-ghost" @click="emit('rollback')">↩ {{ t('topbar.rollback') }}</button>
            <div class="sep"></div>
            <button class="btn btn-ghost" @click="emit('preview')">▷ {{ t('topbar.preview') }}</button>
            <button class="btn btn-outline" @click="emit('validate')">✓ {{ t('topbar.validate') }}</button>
            <button class="btn btn-ghost" @click="emit('saveDraft')">{{ t('topbar.save_draft') }}</button>
            <button class="btn btn-primary" @click="emit('publish')">↑ {{ t('topbar.publish') }}</button>
        </div>
    </div>
</template>

<style scoped>
.topbar {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    height: 52px;
    display: flex;
    align-items: center;
    padding: 0 16px;
    gap: 12px;
    flex-shrink: 0;
    z-index: 10;
}
.topbar-left {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
}
.btn-back {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    color: var(--text-2);
    text-decoration: none;
    font-size: 14px;
    transition: all .15s;
    flex-shrink: 0;
}
.btn-back:hover {
    background: var(--surface-2);
    color: var(--text);
}
.flow-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
}
.version-badge { display: flex; align-items: center; gap: 5px; }
.badge {
    font-size: 11px;
    font-family: 'DM Mono', monospace;
    padding: 2px 7px;
    border-radius: 4px;
    font-weight: 500;
}
.badge-draft { background: var(--amber-bg); color: var(--amber); border: 1px solid #e8d8b8; }
.badge-pub   { background: var(--sage-bg);  color: var(--sage);  border: 1px solid #cdddd4; }
.save-status { font-size: 12px; }

.sep { width: 1px; height: 20px; background: var(--border); margin: 0 2px; }

.topbar-tabs {
    display: flex;
    gap: 2px;
    background: var(--surface-2);
    border: 1px solid var(--border);
    border-radius: 7px;
    padding: 3px;
}
.tab-btn {
    padding: 4px 14px;
    border-radius: 5px;
    border: none;
    background: transparent;
    font-family: 'DM Sans', sans-serif;
    font-size: 12.5px;
    font-weight: 500;
    color: var(--text-2);
    cursor: pointer;
    transition: all .15s;
}
.tab-btn.active {
    background: var(--surface);
    color: var(--text);
    box-shadow: var(--shadow);
}

.topbar-right { display: flex; align-items: center; gap: 6px; }
.btn {
    padding: 6px 12px;
    border-radius: var(--radius);
    font-family: 'DM Sans', sans-serif;
    font-size: 12.5px;
    font-weight: 500;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all .15s;
    display: flex;
    align-items: center;
    gap: 5px;
}
.btn-ghost   { background: transparent; color: var(--text-2); border-color: var(--border); }
.btn-ghost:hover { background: var(--surface-2); color: var(--text); }
.btn-outline { background: transparent; color: var(--accent); border-color: var(--border-2); }
.btn-outline:hover { background: var(--surface-2); }
.btn-primary { background: var(--primary); color: #fff; border-color: var(--primary); }
.btn-primary:hover { background: #4a5c48; }
.btn:disabled { opacity: .3; cursor: not-allowed; }
</style>
