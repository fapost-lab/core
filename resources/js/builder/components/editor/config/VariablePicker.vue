<script setup lang="ts">
import {computed, nextTick, onUnmounted, ref, watch} from 'vue'
import {type PickerSourceKind, type PickerVariable, useFlowVariables} from '@builder/composables/useFlowVariables'
import {groupVariablesBySource, SOURCE_ORDER, splitContactByGroup,} from '@builder/utils/variableGrouping'

const emit = defineEmits<{
    (e: 'select', snippet: string): void
}>()

const { allVars } = useFlowVariables()

const open         = ref(false)
const copied       = ref<string | null>(null)
const rootRef      = ref<HTMLElement | null>(null)
const popoverRef   = ref<HTMLElement | null>(null)
const popoverStyle = ref<Record<string, string>>({})
const searchInput  = ref<HTMLInputElement | null>(null)
const searchQuery  = ref('')

// Section collapse state. Initial set is empty — all collapsed by default.
// Search overrides this and force-expands every section that has matches,
// so the user always sees what they typed without extra clicks.
const collapsedSections = ref<Set<PickerSourceKind>>(new Set(['contact-profile', 'temporary', 'last-user-message', 'rag', 'api-response', 'module']))

// ── Sectioning ───────────────────────────────────────────────────────────────

interface Section {
    kind:     PickerSourceKind
    icon:     string
    title:    string
    flatVars: PickerVariable[]
    groups:   Array<{ name: string; vars: PickerVariable[] }>
    total:    number
}

const SOURCE_META: Record<PickerSourceKind, { icon: string; title: string }> = {
    'contact-profile':   { icon: '💾', title: 'Contact profile' },
    'temporary':         { icon: '⏱', title: 'Temporary' },
    // Holds every `system.*` path — the last inbound message, language, retry
    // count, and the channel the session runs on.
    'last-user-message': { icon: '💬', title: 'System' },
    'rag':               { icon: '🧠', title: 'RAG' },
    'api-response':      { icon: '⚡', title: 'API response' },
    'module':            { icon: '🏢', title: 'Module' },
}

function matchesSearch(v: PickerVariable, q: string): boolean {
    if (q === '') return true
    const needle = q.toLowerCase()
    return v.label.toLowerCase().includes(needle)
        || v.snippet.toLowerCase().includes(needle)
}

// A json variable stays visible when the query matches the parent OR any of
// its nested fields, so searching a field name surfaces it under its parent.
function matchesDeep(v: PickerVariable, q: string): boolean {
    if (matchesSearch(v, q)) return true
    return !!v.children?.some((c) => matchesSearch(c, q))
}

// Which json variables are expanded. A search auto-expands everything.
const expandedVars = ref<Set<string>>(new Set())
function toggleVar(path: string) {
    const next = new Set(expandedVars.value)
    next.has(path) ? next.delete(path) : next.add(path)
    expandedVars.value = next
}
function isVarExpanded(path: string): boolean {
    return searchQuery.value.trim() !== '' || expandedVars.value.has(path)
}
function visibleChildren(v: PickerVariable): PickerVariable[] {
    if (!v.children) return []
    const q = searchQuery.value.trim()
    return q === '' ? v.children : v.children.filter((c) => matchesSearch(c, q))
}

const sections = computed<Section[]>(() => {
    const q        = searchQuery.value.trim()
    const bySource = groupVariablesBySource(allVars.value)
    const out: Section[] = []

    for (const kind of SOURCE_ORDER) {
        const vars = bySource[kind]
        if (!vars || vars.length === 0) {
            continue
        }
        const meta = SOURCE_META[kind]

        if (kind === 'contact-profile') {
            const split = splitContactByGroup(vars)
            const flatVars = split.rootVars.filter((v) => matchesDeep(v, q))
            const groups = Object.entries(split.groupedVars)
                .sort(([a], [b]) => a.localeCompare(b))
                .map(([name, list]) => ({ name, vars: list.filter((v) => matchesDeep(v, q)) }))
                .filter((g) => g.vars.length > 0)
            const total = flatVars.length + groups.reduce((sum, g) => sum + g.vars.length, 0)
            if (total === 0) continue
            out.push({ kind, icon: meta.icon, title: meta.title, flatVars, groups, total })
        } else {
            const flatVars = vars.filter((v) => matchesDeep(v, q))
            if (flatVars.length === 0) continue
            out.push({ kind, icon: meta.icon, title: meta.title, flatVars, groups: [], total: flatVars.length })
        }
    }

    return out
})

// Search active → expand every section so matches are visible without clicks.
function isExpanded(kind: PickerSourceKind): boolean {
    if (searchQuery.value.trim() !== '') return true
    return !collapsedSections.value.has(kind)
}

function toggleSection(kind: PickerSourceKind) {
    const next = new Set(collapsedSections.value)
    if (next.has(kind)) next.delete(kind)
    else next.add(kind)
    collapsedSections.value = next
}

// ── Positioning + click-outside ─────────────────────────────────────────────

// Preferred max content height for the popover (matches CSS .vp-popover max-height).
// Used to decide flip direction and to clamp inline when neither side has full room.
const POPOVER_MAX_H = 320
const VIEWPORT_GAP  = 8     // breathing room from viewport edges
const TRIGGER_GAP   = 4     // distance between trigger and popover

function reposition() {
    if (!rootRef.value) return
    const rect    = rootRef.value.getBoundingClientRect()
    const vw      = window.innerWidth
    const vh      = window.innerHeight

    const spaceBelow = vh - rect.bottom - VIEWPORT_GAP
    const spaceAbove = rect.top - VIEWPORT_GAP

    // Prefer the side with more room. If both are tight, the chosen side gets
    // a clamped max-height so the popover never overflows the viewport.
    const placeBelow = spaceBelow >= spaceAbove
    const available  = placeBelow ? spaceBelow : spaceAbove
    const maxHeight  = Math.max(160, Math.min(POPOVER_MAX_H, available))

    // Right-align to trigger but clamp to keep the left edge on-screen.
    // Width unknown until rendered — use min-width (240) as a safe assumption.
    const minWidth   = 240
    const rightAnchor = Math.max(VIEWPORT_GAP, vw - rect.right)
    const leftEdge    = vw - rightAnchor - minWidth
    const adjustedRight = leftEdge < VIEWPORT_GAP
        ? Math.max(VIEWPORT_GAP, vw - minWidth - VIEWPORT_GAP)
        : rightAnchor

    popoverStyle.value = placeBelow
        ? {
            position:  'fixed',
            top:       `${rect.bottom + TRIGGER_GAP}px`,
            right:     `${adjustedRight}px`,
            maxHeight: `${maxHeight}px`,
        }
        : {
            position:  'fixed',
            bottom:    `${vh - rect.top + TRIGGER_GAP}px`,
            right:     `${adjustedRight}px`,
            maxHeight: `${maxHeight}px`,
        }
}

function onDocClick(e: MouseEvent) {
    const inTrigger = rootRef.value?.contains(e.target as Node)
    const inPopover = popoverRef.value?.contains(e.target as Node)
    if (!inTrigger && !inPopover) open.value = false
}

// Reposition on outer scroll/resize so the popover follows the trigger when the
// page scrolls. Capture phase catches scroll on any ancestor (config panel,
// body, etc.) without each one needing its own listener. We do NOT preventDefault
// or otherwise interfere with scrolling — the popover just re-anchors.
function onViewportChange() {
    if (open.value) reposition()
}

watch(open, async (val) => {
    if (val) {
        await nextTick()
        reposition()
        searchInput.value?.focus()
        document.addEventListener('mousedown', onDocClick, { capture: true })
        window.addEventListener('resize', onViewportChange)
        window.addEventListener('scroll', onViewportChange, { capture: true, passive: true })
    } else {
        searchQuery.value = ''
        document.removeEventListener('mousedown', onDocClick, { capture: true })
        window.removeEventListener('resize', onViewportChange)
        window.removeEventListener('scroll', onViewportChange, { capture: true })
    }
})

onUnmounted(() => {
    document.removeEventListener('mousedown', onDocClick, { capture: true })
    window.removeEventListener('resize', onViewportChange)
    window.removeEventListener('scroll', onViewportChange, { capture: true })
})

// ── Actions ─────────────────────────────────────────────────────────────────

async function selectVar(v: PickerVariable) {
    const text = v.snippet
    emit('select', text)
    try { await navigator.clipboard.writeText(text) } catch { /* ignore */ }
    copied.value = v.path
    setTimeout(() => { copied.value = null }, 1400)
}

function onDragStart(v: PickerVariable, e: DragEvent) {
    if (e.dataTransfer) {
        e.dataTransfer.setData('text/plain', v.snippet)
        e.dataTransfer.effectAllowed = 'copy'
    }
}
</script>

<template>
    <div ref="rootRef" class="vp">
        <button
            class="vp-trigger"
            :class="{ 'vp-trigger--open': open }"
            type="button"
            title="Insert variable"
            @click="open = !open"
        >{{ '{…}' }}</button>

        <Teleport to="body">
            <div v-if="open" ref="popoverRef" class="vp-popover" :style="popoverStyle">
                <div class="vp-search">
                    <input
                        ref="searchInput"
                        v-model="searchQuery"
                        type="text"
                        class="vp-search-input"
                        placeholder="Search variables…"
                        autocomplete="off"
                        spellcheck="false"
                        @keydown.esc.stop="searchQuery ? (searchQuery = '') : (open = false)"
                    >
                    <button
                        v-if="searchQuery"
                        type="button"
                        class="vp-search-clear"
                        title="Clear search"
                        @click="searchQuery = ''; searchInput?.focus()"
                    >×</button>
                </div>

                <div class="vp-scroll">
                    <template v-for="section in sections" :key="section.kind">
                        <button
                            type="button"
                            class="vp-group-label"
                            :aria-expanded="isExpanded(section.kind)"
                            @click="toggleSection(section.kind)"
                        >
                            <span class="vp-chevron" :class="{ 'vp-chevron--open': isExpanded(section.kind) }">▸</span>
                            <span class="vp-group-icon">{{ section.icon }}</span>
                            <span class="vp-group-title">{{ section.title }}</span>
                            <span class="vp-group-count">{{ section.total }}</span>
                        </button>

                        <template v-if="isExpanded(section.kind)">
                            <template v-for="v in section.flatVars" :key="v.path">
                                <div
                                    class="vp-item"
                                    :class="{ 'vp-item--parent': v.children?.length }"
                                    draggable="true"
                                    :title="v.sourceNode ? `from ${v.sourceNode}` : 'Click to insert/copy · drag to drop'"
                                    @click="selectVar(v)"
                                    @dragstart="onDragStart(v, $event)"
                                >
                                    <span
                                        v-if="v.children?.length"
                                        class="vp-expand"
                                        :title="isVarExpanded(v.path) ? 'Collapse' : 'Show fields'"
                                        @click.stop="toggleVar(v.path)"
                                    >{{ isVarExpanded(v.path) ? '▾' : '▸' }}</span>
                                    <span class="vp-key">{{ v.snippet }}</span>
                                    <span v-if="v.children?.length" class="vp-children-count">{{ v.children.length }}</span>
                                    <span class="vp-badge" :class="{ 'vp-badge--ok': copied === v.path }">
                                        {{ copied === v.path ? '✓' : 'use' }}
                                    </span>
                                </div>
                                <template v-if="v.children?.length && isVarExpanded(v.path)">
                                    <div
                                        v-for="c in visibleChildren(v)"
                                        :key="c.path"
                                        class="vp-item vp-item--json-child"
                                        draggable="true"
                                        :title="`Insert ${c.snippet}`"
                                        @click="selectVar(c)"
                                        @dragstart="onDragStart(c, $event)"
                                    >
                                        <span class="vp-key vp-key--rel">{{ c.label }}</span>
                                        <span class="vp-badge" :class="{ 'vp-badge--ok': copied === c.path }">
                                            {{ copied === c.path ? '✓' : 'use' }}
                                        </span>
                                    </div>
                                </template>
                            </template>

                            <template v-for="group in section.groups" :key="`${section.kind}:${group.name}`">
                                <div class="vp-subgroup-label">─── {{ group.name }} ───</div>
                                <div
                                    v-for="v in group.vars"
                                    :key="v.path"
                                    class="vp-item vp-item--nested"
                                    draggable="true"
                                    :title="v.sourceNode ? `from ${v.sourceNode}` : 'Click to insert/copy · drag to drop'"
                                    @click="selectVar(v)"
                                    @dragstart="onDragStart(v, $event)"
                                >
                                    <span class="vp-key">{{ v.snippet }}</span>
                                    <span class="vp-badge" :class="{ 'vp-badge--ok': copied === v.path }">
                                        {{ copied === v.path ? '✓' : 'use' }}
                                    </span>
                                </div>
                            </template>
                        </template>
                    </template>

                    <div v-if="sections.length === 0" class="vp-empty">
                        {{ searchQuery ? 'No matches' : 'No variables yet' }}
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.vp { position: relative; display: inline-flex; }

.vp-trigger {
    height: 20px;
    padding: 0 6px;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--surface);
    font-family: var(--font-mono);
    font-size: 10px;
    color: var(--text-3);
    cursor: pointer;
    white-space: nowrap;
    transition: border-color .1s, color .1s;
}
.vp-trigger:hover,
.vp-trigger--open { border-color: var(--primary); color: var(--primary); }

.vp-popover {
    z-index: 9999;
    min-width: 260px;
    max-height: 320px;
    display: flex;
    flex-direction: column;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0,0,0,.10);
    overflow: hidden;
}
.vp-scroll {
    flex: 1;
    overflow-y: auto;
    padding: 4px 0;
    min-height: 0;
}

.vp-search {
    position: relative;
    padding: 6px;
    border-bottom: 1px solid var(--border);
    background: var(--surface);
}
.vp-search-input {
    width: 100%;
    padding: 5px 24px 5px 8px;
    border: 1px solid var(--border);
    border-radius: 4px;
    background: var(--surface);
    font-size: 12px;
    font-family: inherit;
    color: var(--text);
}
.vp-search-input:focus { outline: none; border-color: var(--primary); }
.vp-search-clear {
    position: absolute;
    top: 50%;
    right: 10px;
    transform: translateY(-50%);
    width: 18px;
    height: 18px;
    border: none;
    border-radius: 4px;
    background: transparent;
    color: var(--text-3);
    font-size: 14px;
    line-height: 1;
    cursor: pointer;
}
.vp-search-clear:hover { background: var(--surface-2); color: var(--text); }

.vp-group-label {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    padding: 7px 10px;
    border: none;
    background: transparent;
    font-family: inherit;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-3);
    cursor: pointer;
    text-align: left;
}
.vp-group-label:hover { background: var(--surface-2, #f4f5f6); }
.vp-chevron {
    display: inline-block;
    width: 8px;
    color: var(--text-3);
    transition: transform 120ms;
    font-size: 9px;
}
.vp-chevron--open { transform: rotate(90deg); }
.vp-group-icon { font-size: 12px; }
.vp-group-title { flex: 1; }
.vp-group-count {
    padding: 1px 6px;
    border-radius: 8px;
    background: var(--surface-2, #f0f1f2);
    color: var(--text-2);
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0;
    text-transform: none;
}

.vp-subgroup-label {
    padding: 4px 10px 2px 18px;
    font-size: 10px;
    color: var(--text-3);
    opacity: .8;
    font-family: var(--font-mono);
}

.vp-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 5px 10px;
    cursor: pointer;
    transition: background .1s;
}
.vp-item--nested { padding-left: 18px; }
.vp-item:hover { background: var(--surface-2, #f4f5f6); }

.vp-expand {
    flex-shrink: 0;
    width: 12px;
    margin-right: 2px;
    color: var(--text-3);
    font-size: 9px;
    cursor: pointer;
    user-select: none;
}
.vp-expand:hover { color: var(--primary); }
.vp-children-count {
    flex-shrink: 0;
    font-size: 9.5px;
    color: var(--text-3);
    background: var(--surface-2, #eef1f4);
    border-radius: 8px;
    padding: 0 5px;
    margin-left: 6px;
}
.vp-item--json-child {
    padding-left: 26px;
    background: color-mix(in srgb, var(--primary, #5b7fa6) 4%, transparent);
}
.vp-key--rel { color: var(--text-2); font-size: 11px; }

.vp-key {
    font-family: var(--font-mono);
    font-size: 11.5px;
    color: var(--text);
    flex: 1;
}

.vp-badge {
    font-size: 10px;
    color: var(--text-3);
    flex-shrink: 0;
    margin-left: 8px;
    transition: color .1s;
}
.vp-badge--ok { color: var(--success, #38a169); font-weight: 600; }

.vp-empty {
    padding: 10px;
    font-size: 11px;
    color: var(--text-3);
    text-align: center;
}
</style>
