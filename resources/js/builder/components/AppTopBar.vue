<script setup lang="ts">
import {computed} from 'vue'
import {usePage} from '@inertiajs/vue3'
import {useTranslations} from '../composables/useTranslations.js'

const { t } = useTranslations()

/**
 * Interface language switcher. The selected locale is persisted server-side
 * (session + long-lived cookie) by the SetLocale middleware via ?lang=, so the
 * first choice is automatic and every later visit reads it back from storage.
 */
const LOCALES = [
    { code: 'en', label: 'EN' },
    { code: 'ru', label: 'RU' },
    { code: 'uk', label: 'UK' },
] as const

const page = usePage()
const currentLocale = computed<string>(() => {
    const code = (page.props as Record<string, unknown>)['locale']
    return typeof code === 'string' ? code : 'en'
})

function switchLocale(event: Event): void {
    const code = (event.target as HTMLSelectElement).value
    if (code === currentLocale.value) {
        return
    }
    const url = new URL(window.location.href)
    url.searchParams.set('lang', code)
    // Full reload so middleware persists the choice and fresh translations load.
    window.location.assign(url.toString())
}

defineProps({
    flowName:         { type: String,  required: true },
    draftVersion:     { type: Number as () => number | null,  default: null },
    publishedVersion: { type: Number as () => number | null,  default: null },
    saveStatus:       { type: String,  default: 'idle' },
    isDirty:          { type: Boolean, default: false },
    activeTab:        { type: String,  default: 'builder' },
    backUrl:          { type: String as () => string | null,  default: null },
    canUndo:          { type: Boolean, default: false },
    canRedo:          { type: Boolean, default: false },
    validating:       { type: Boolean, default: false },
    publishing:       { type: Boolean, default: false },
    publishedMsg:     { type: String as () => string | null, default: null },
})

const emit = defineEmits([
    'tabChange', 'validate', 'saveDraft', 'publish', 'undo', 'redo', 'reload',
])

const SAVE_COLORS: Record<string, string> = {
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
            <span class="flow-name">
                {{ flowName }}
                <span
                    v-if="isDirty"
                    class="dirty-bullet"
                    :title="t('topbar.dirty_title')"
                >●</span>
            </span>
            <div class="version-badge">
                <span v-if="publishedVersion != null" class="badge badge-pub">Published v{{ publishedVersion }}</span>
                <span v-else class="badge badge-muted">Not published</span>
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
            <!-- A conflict is a dead end: the draft moved on elsewhere and every
                 later edit is rejected. Reloading is the only way out, so offer
                 it right next to the status instead of leaving the author to
                 guess. -->
            <button
                v-if="saveStatus === 'conflict'"
                class="btn-reload"
                :title="t('topbar.reload_title')"
                type="button"
                @click="emit('reload')"
            >
                ↻ {{ t('topbar.reload') }}
            </button>
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
            <select
                class="lang-select"
                :value="currentLocale"
                :title="t('topbar.language')"
                @change="switchLocale"
            >
                <option v-for="l in LOCALES" :key="l.code" :value="l.code">{{ l.label }}</option>
            </select>
            <button class="btn btn-ghost" :disabled="!canUndo" @click="emit('undo')">↩ Undo</button>
            <button class="btn btn-ghost" :disabled="!canRedo" @click="emit('redo')">↪ Redo</button>
            <button class="btn btn-outline" :disabled="validating" @click="emit('validate')">
                {{ validating ? 'Validating…' : `✓ ${t('topbar.validate')}` }}
            </button>
            <button class="btn btn-ghost" @click="emit('saveDraft')">{{ t('topbar.save_draft') }}</button>
            <button class="btn btn-primary" :disabled="publishing" @click="emit('publish')">
                {{ publishing ? 'Publishing…' : `↑ ${t('topbar.publish')}` }}
            </button>
            <Transition name="fade">
                <span v-if="publishedMsg" class="published-msg">{{ publishedMsg }}</span>
            </Transition>
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
    position: relative;
    z-index: 10;
}
.topbar-left {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
    min-width: 0;
}
.dirty-bullet {
    display: inline-block;
    margin-left: 6px;
    font-size: 13px;
    line-height: 1;
    color: var(--amber, #d69e2e);
    transform: translateY(-1px);
}
.lang-select {
    height: 28px;
    padding: 0 22px 0 8px;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    background: var(--surface);
    color: var(--text-2);
    font-family: 'DM Sans', sans-serif;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath fill='none' stroke='%23a09b94' stroke-width='1.5' d='M1 1l4 4 4-4'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 7px center;
    transition: all .15s;
}
.lang-select:hover {
    border-color: var(--border-2);
    color: var(--text);
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
.btn-reload {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    height: 24px;
    padding: 0 8px;
    border-radius: var(--radius);
    border: 1px solid var(--amber);
    background: transparent;
    color: var(--amber);
    font-size: 12px;
    cursor: pointer;
    transition: all .15s;
    flex-shrink: 0;
}
.btn-reload:hover {
    background: var(--amber);
    color: #fff;
}
.flow-name {
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
}
.version-badge { display: flex; align-items: center; gap: 5px; }
.badge {
    font-size: 11px;
    font-family: 'Victor Mono', monospace;
    padding: 2px 7px;
    border-radius: 4px;
    font-weight: 500;
}
.badge-draft { background: var(--amber-bg); color: var(--amber); border: 1px solid #e8d8b8; }
.badge-pub   { background: var(--sage-bg);  color: var(--sage);  border: 1px solid #cdddd4; }
.badge-muted { background: var(--surface-2); color: var(--text-3); border: 1px solid var(--border); }
.save-status { font-size: 12px; }

.sep { width: 1px; height: 20px; background: var(--border); margin: 0 2px; }

.topbar-tabs {
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 2px;
    background: var(--surface-2);
    border: 1px solid var(--border-2);
    border-radius: 8px;
    padding: 3px;
}
.tab-btn {
    padding: 5px 20px;
    border-radius: 6px;
    border: none;
    background: transparent;
    font-family: 'DM Sans', sans-serif;
    font-size: 13px;
    font-weight: 500;
    color: var(--text-3);
    cursor: pointer;
    transition: all .15s;
}
.tab-btn.active {
    background: var(--surface);
    color: var(--text);
    font-weight: 600;
    box-shadow: 0 1px 3px rgba(0,0,0,.12);
}

.topbar-right { display: flex; align-items: center; gap: 6px; flex: 1; justify-content: flex-end; }
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

.published-msg {
    color: var(--sage);
    font-size: 12px;
    font-weight: 500;
}

.fade-enter-active,
.fade-leave-active {
    transition: opacity .3s;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
