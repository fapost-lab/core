<script setup lang="ts">
/**
 * Searchable single-select combobox. Renders a trigger showing the current
 * value; opening reveals a filter input + the matching options in a
 * teleported, viewport-anchored dropdown.
 *
 * When `allowCustom` is set, a value typed in the search box that matches no
 * option can be committed verbatim — used for the call node's action id, which
 * a Solution may register only after the flow is authored.
 */
import {computed, nextTick, onUnmounted, ref, watch} from 'vue'

const props = defineProps<{
    modelValue:   string
    options:      string[]
    placeholder?: string
    allowCustom?: boolean
    mono?:        boolean
    emptyText?:   string
}>()

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void
}>()

const open       = ref(false)
const query      = ref('')
const rootRef    = ref<HTMLElement | null>(null)
const triggerRef = ref<HTMLButtonElement | null>(null)
const menuRef    = ref<HTMLElement | null>(null)
const searchRef  = ref<HTMLInputElement | null>(null)

interface MenuPos { top: number; left: number; width: number }
const menuPos = ref<MenuPos>({ top: 0, left: 0, width: 0 })

const filtered = computed<string[]>(() => {
    const q = query.value.trim().toLowerCase()
    if (q === '') return props.options
    return props.options.filter(o => o.toLowerCase().includes(q))
})

const showCustom = computed<boolean>(() => {
    const q = query.value.trim()
    return !!props.allowCustom && q !== '' && !props.options.includes(q)
})

function reposition() {
    const el = triggerRef.value
    if (!el) return
    const rect = el.getBoundingClientRect()
    menuPos.value = { top: rect.bottom + 4, left: rect.left, width: rect.width }
}

async function toggle() {
    if (!open.value) {
        reposition()
        open.value = true
        query.value = ''
        await nextTick()
        searchRef.value?.focus()
    } else {
        open.value = false
    }
}

function close() { open.value = false }

function pick(value: string) {
    emit('update:modelValue', value)
    close()
}

function commitCustom() {
    const q = query.value.trim()
    if (q !== '') pick(q)
}

function onSearchKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') { event.preventDefault(); close(); return }
    if (event.key === 'Enter') {
        event.preventDefault()
        if (filtered.value.length === 1) { pick(filtered.value[0]); return }
        if (showCustom.value) commitCustom()
    }
}

function onDocClick(e: MouseEvent) {
    const target = e.target as Node
    if (rootRef.value?.contains(target)) return
    if (menuRef.value?.contains(target)) return
    close()
}
function onReposition() { if (open.value) reposition() }

watch(open, (val) => {
    if (val) {
        document.addEventListener('mousedown', onDocClick, { capture: true })
        window.addEventListener('scroll', onReposition, true)
        window.addEventListener('resize', onReposition)
    } else {
        document.removeEventListener('mousedown', onDocClick, { capture: true })
        window.removeEventListener('scroll', onReposition, true)
        window.removeEventListener('resize', onReposition)
    }
})

onUnmounted(() => {
    document.removeEventListener('mousedown', onDocClick, { capture: true })
    window.removeEventListener('scroll', onReposition, true)
    window.removeEventListener('resize', onReposition)
})
</script>

<template>
    <div ref="rootRef" class="ss">
        <button
            ref="triggerRef"
            type="button"
            class="ss-trigger"
            :class="{ 'ss-trigger--placeholder': modelValue === '', 'ss-trigger--open': open, mono }"
            :aria-expanded="open"
            @click="toggle"
        >
            <span class="ss-trigger-label">{{ modelValue || (placeholder ?? 'Select…') }}</span>
            <span class="ss-caret" :class="{ 'ss-caret--open': open }">▾</span>
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                ref="menuRef"
                :style="{ top: `${menuPos.top}px`, left: `${menuPos.left}px`, width: `${menuPos.width}px` }"
                class="ss-menu"
                role="listbox"
            >
                <input
                    ref="searchRef"
                    v-model="query"
                    type="text"
                    class="ss-search"
                    placeholder="Search…"
                    autocomplete="off"
                    spellcheck="false"
                    @keydown="onSearchKeydown"
                >

                <div class="ss-list">
                    <button
                        v-for="opt in filtered"
                        :key="opt"
                        class="ss-option"
                        :class="{ 'ss-option--active': modelValue === opt, mono }"
                        type="button"
                        role="option"
                        @click="pick(opt)"
                    >
                        <span class="ss-option-mark">{{ modelValue === opt ? '✓' : '' }}</span>
                        <span class="ss-option-label">{{ opt }}</span>
                    </button>

                    <button
                        v-if="showCustom"
                        class="ss-option ss-option--custom"
                        type="button"
                        @click="commitCustom"
                    >
                        <span class="ss-option-mark">✎</span>
                        <span class="ss-option-label">Use “{{ query.trim() }}”</span>
                    </button>

                    <div v-if="filtered.length === 0 && !showCustom" class="ss-empty">
                        {{ emptyText ?? 'No options' }}
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.ss { position: relative; width: 100%; }

.ss-trigger {
    display: flex; align-items: center; justify-content: space-between; gap: 8px;
    width: 100%; padding: 6px 9px;
    border: 1px solid var(--border); border-radius: 6px;
    background: var(--surface-2); color: var(--text);
    font-family: 'DM Sans', sans-serif; font-size: 12.5px;
    cursor: pointer; text-align: left;
}
.ss-trigger.mono { font-family: 'Victor Mono', monospace; font-size: 12px; }
.ss-trigger--open { border-color: var(--primary); background: #fff; }
.ss-trigger--placeholder .ss-trigger-label { color: var(--text-3); }
.ss-trigger-label { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ss-caret { color: var(--text-3); font-size: 11px; flex-shrink: 0; transition: transform 120ms; }
.ss-caret--open { transform: rotate(180deg); }

.ss-menu {
    position: fixed; z-index: 60; padding: 4px;
    background: var(--surface); border: 1px solid var(--border); border-radius: 6px;
    box-shadow: 0 4px 16px rgba(0,0,0,.1);
}
.ss-search {
    width: 100%; padding: 6px 8px; margin-bottom: 4px;
    border: 1px solid var(--border); border-radius: 4px;
    background: var(--surface-2); color: var(--text);
    font-family: 'DM Sans', sans-serif; font-size: 12.5px; outline: none;
}
.ss-search:focus { border-color: var(--primary); background: #fff; }
.ss-list { max-height: 220px; overflow-y: auto; }
.ss-option {
    display: flex; align-items: center; gap: 8px; width: 100%;
    padding: 6px 8px; border: none; border-radius: 4px;
    background: transparent; color: var(--text);
    font-family: 'DM Sans', sans-serif; font-size: 12.5px;
    text-align: left; cursor: pointer;
}
.ss-option.mono .ss-option-label { font-family: 'Victor Mono', monospace; font-size: 12px; }
.ss-option:hover { background: var(--surface-2, #f4f5f6); }
.ss-option--active { background: var(--primary-bg, rgba(0,0,0,.04)); }
.ss-option--custom { color: var(--primary); }
.ss-option-mark { flex: 0 0 14px; text-align: center; color: var(--primary); font-size: 11px; }
.ss-option-label { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ss-empty { padding: 8px; font-size: 11.5px; color: var(--text-3); text-align: center; }
</style>
