<script setup lang="ts">
import {nextTick, onUnmounted, ref, watch} from 'vue'
import {useFlowVariables} from '@builder/composables/useFlowVariables'

const { groups } = useFlowVariables()

const open         = ref(false)
const copied       = ref<string | null>(null)
const rootRef      = ref<HTMLElement | null>(null)
const popoverRef   = ref<HTMLElement | null>(null)
const popoverStyle = ref<Record<string, string>>({})

function reposition() {
    if (!rootRef.value) return
    const rect = rootRef.value.getBoundingClientRect()
    popoverStyle.value = {
        position: 'fixed',
        bottom:   `${window.innerHeight - rect.top + 4}px`,
        right:    `${window.innerWidth - rect.right}px`,
    }
}

// ── Click-outside to close ────────────────────────────────────────────────────
function onDocClick(e: MouseEvent) {
    const inTrigger = rootRef.value?.contains(e.target as Node)
    const inPopover = popoverRef.value?.contains(e.target as Node)
    if (!inTrigger && !inPopover) open.value = false
}

watch(open, async (val) => {
    if (val) {
        await nextTick()
        reposition()
        document.addEventListener('mousedown', onDocClick, { capture: true })
    } else {
        document.removeEventListener('mousedown', onDocClick, { capture: true })
    }
})

onUnmounted(() => document.removeEventListener('mousedown', onDocClick, { capture: true }))

// ── Actions ───────────────────────────────────────────────────────────────────
function snippet(key: string) { return `{{${key}}}` }

async function copyVar(key: string) {
    const snippet = `{{${key}}}`
    try { await navigator.clipboard.writeText(snippet) } catch { /* ignore */ }
    copied.value = key
    setTimeout(() => { copied.value = null }, 1400)
}

function onDragStart(key: string, e: DragEvent) {
    if (e.dataTransfer) {
        e.dataTransfer.setData('text/plain', `{{${key}}}`)
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
                <template v-for="group in groups" :key="group.ns">
                    <div v-if="group.vars.length > 0" class="vp-group-label">{{ group.label }}</div>
                    <div
                        v-for="v in group.vars"
                        :key="v.key"
                        class="vp-item"
                        draggable="true"
                        @click="copyVar(v.key)"
                        @dragstart="onDragStart(v.key, $event)"
                    >
                        <span class="vp-key">{{ snippet(v.key) }}</span>
                        <span class="vp-badge" :class="{ 'vp-badge--ok': copied === v.key }">
                            {{ copied === v.key ? '✓' : 'copy' }}
                        </span>
                    </div>
                </template>
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
    font-family: 'DM Mono', monospace;
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
    min-width: 220px;
    max-height: 260px;
    overflow-y: auto;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: 0 4px 16px rgba(0,0,0,.10);
    padding: 4px 0;
}

.vp-group-label {
    padding: 6px 10px 2px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--text-3);
}

.vp-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 5px 10px;
    cursor: pointer;
    transition: background .1s;
}
.vp-item:hover { background: var(--surface-2, #f4f5f6); }

.vp-key {
    font-family: 'DM Mono', monospace;
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
</style>
