<script setup lang="ts">
import {computed} from 'vue'
import {usePage} from '@inertiajs/vue3'
import {Globe, Monitor, Moon, Sun} from '@lucide/vue'
import {isThemePreference} from '@shared/theme/theme'
import {useTheme} from '@shared/theme/useTheme'
import {useTranslations} from '../composables/useTranslations.js'
import TopBarMenu from './TopBarMenu.vue'

const { t } = useTranslations()

// The same preference as the console (localStorage + `.dark` on <html>), so the
// builder opens in the theme the author chose there.
const { preference, setPreference } = useTheme()

const themeIcon = computed(() => (preference.value === 'dark' ? Moon : preference.value === 'light' ? Sun : Monitor))
const themeOptions = computed(() => [
    { value: 'light', label: t('topbar.theme_light') },
    { value: 'dark', label: t('topbar.theme_dark') },
    { value: 'system', label: t('topbar.theme_system') },
])

function switchTheme(value: string): void {
    if (isThemePreference(value)) {
        setPreference(value)
    }
}

/**
 * Interface language switcher. The selected locale is persisted server-side
 * (session + long-lived cookie) by the SetLocale middleware via ?lang=, so the
 * first choice is automatic and every later visit reads it back from storage.
 */
/** Each language under its own name, so it can be found whatever the current one is. */
const LOCALES = [
    { value: 'en', label: 'English' },
    { value: 'ru', label: 'Русский' },
    { value: 'uk', label: 'Українська' },
] as const

const page = usePage()
const currentLocale = computed<string>(() => {
    const code = (page.props as Record<string, unknown>)['locale']
    return typeof code === 'string' ? code : 'en'
})

function switchLocale(code: string): void {
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
                <span v-if="publishedVersion != null" class="badge badge-pub">{{ t('topbar.published_version').replace(':version', String(publishedVersion)) }}</span>
                <span v-else class="badge badge-muted">{{ t('topbar.not_published') }}</span>
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
            <TopBarMenu :label="t('topbar.language')" :value="currentLocale" :options="LOCALES" @select="switchLocale">
                <Globe :size="15" aria-hidden="true" />
                <span class="locale-code">{{ currentLocale }}</span>
            </TopBarMenu>
            <TopBarMenu :label="t('topbar.theme')" :value="preference" :options="themeOptions" @select="switchTheme">
                <component :is="themeIcon" :size="15" aria-hidden="true" />
            </TopBarMenu>
            <button
                class="btn btn-ghost btn-icon"
                :disabled="!canUndo"
                :title="t('topbar.undo')"
                :aria-label="t('topbar.undo')"
                @click="emit('undo')"
            >↩</button>
            <button
                class="btn btn-ghost btn-icon"
                :disabled="!canRedo"
                :title="t('topbar.redo')"
                :aria-label="t('topbar.redo')"
                @click="emit('redo')"
            >↪</button>
            <button class="btn btn-outline" :disabled="validating" @click="emit('validate')">
                {{ validating ? t('topbar.validating') : `✓ ${t('topbar.validate')}` }}
            </button>
            <button class="btn btn-ghost" @click="emit('saveDraft')">{{ t('topbar.save_draft') }}</button>
            <button class="btn btn-primary" :disabled="publishing" @click="emit('publish')">
                {{ publishing ? t('topbar.publishing') : `↑ ${t('topbar.publish')}` }}
            </button>
            <Transition name="fade">
                <span v-if="publishedMsg" class="published-msg">{{ publishedMsg }}</span>
            </Transition>
        </div>
    </div>
</template>

<style scoped>
.topbar {
    background: var(--card);
    border-bottom: 1px solid var(--border);
    height: 52px;
    /* The right column never gets narrower than its controls and the left one gives way first, so the tabs stay
       centred when there is room and nothing can slide underneath them in a longer locale. */
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto minmax(max-content, 1fr);
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
    min-width: 0;
    overflow: hidden;
}
.topbar-left .flow-name {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.dirty-bullet {
    display: inline-block;
    margin-left: 6px;
    font-size: 13px;
    line-height: 1;
    color: var(--amber);
    transform: translateY(-1px);
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
    color: var(--on-solid);
}
.flow-name {
    font-family: var(--font-display);
    font-size: 17px;
    font-weight: 600;
    letter-spacing: .01em;
    color: var(--text);
}
.version-badge { display: flex; align-items: center; gap: 5px; }
.badge {
    white-space: nowrap;
    font-size: 11px;
    font-family: var(--font-mono);
    padding: 2px 7px;
    border-radius: 4px;
    font-weight: 500;
}
.badge-draft { background: var(--amber-bg); color: var(--amber); border: 1px solid var(--amber-border); }
.badge-pub   { background: var(--sage-bg);  color: var(--sage);  border: 1px solid var(--sage-border); }
.badge-muted { background: var(--surface-2); color: var(--text-3); border: 1px solid var(--border); }
.save-status { font-size: 12px; }

.sep { width: 1px; height: 20px; background: var(--border); margin: 0 2px; }

.topbar-tabs {
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
    font-family: var(--font-sans);
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
    box-shadow: var(--shadow-tab);
}

.topbar-right {
    display: flex;
    align-items: center;
    gap: 6px;
    justify-content: flex-end;
}
.topbar-right > * { flex-shrink: 0; }
.topbar-right .btn {
    white-space: nowrap;
}
.btn-icon { padding: 6px 9px; }
.locale-code { text-transform: uppercase; }

/* Narrow widths: tighten the tabs and buttons before anything can collide. */
@media (max-width: 1280px) {
    .topbar { padding: 0 10px; gap: 8px; }
    .topbar-right { gap: 4px; }
    .topbar-right .btn { padding: 6px 8px; }
    .tab-btn { padding: 5px 12px; }
    .published-msg { display: none; }
}
.btn {
    padding: 6px 12px;
    border-radius: var(--radius);
    font-family: var(--font-sans);
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
.btn-outline { background: transparent; color: var(--builder-accent); border-color: var(--border-2); }
.btn-outline:hover { background: var(--surface-2); }
.btn-primary { background: var(--primary); color: var(--on-solid); border-color: var(--primary); }
.btn-primary:hover { background: var(--primary-hover); }
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
