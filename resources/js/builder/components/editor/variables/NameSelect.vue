<script setup lang="ts">
/**
 * Variable-name combobox. A single text input drives everything: as the user
 * types, matching known names are suggested below. Picking a suggestion reuses
 * that variable (emits `pick`); typing a name with no match simply becomes a
 * new variable (the typed value is the model). No separate "create" mode.
 */
import {computed, nextTick, onUnmounted, ref, watch} from 'vue'

const props = defineProps<{
    modelValue:  string
    knownNames:  string[]
    placeholder?: string
}>()

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void
    (e: 'pick', value: string): void
}>()

const open      = ref(false)
const activeIdx = ref(-1)
const rootRef   = ref<HTMLElement | null>(null)
const inputRef  = ref<HTMLInputElement | null>(null)
const menuRef   = ref<HTMLElement | null>(null)

interface MenuPos { top: number; left: number; width: number }
const menuPos = ref<MenuPos>({ top: 0, left: 0, width: 0 })

// Filter known names by the current query (case-insensitive substring).
const matches = computed<string[]>(() => {
    const q = props.modelValue.trim().toLowerCase()
    if (q === '') return props.knownNames
    return props.knownNames.filter((n) => n.toLowerCase().includes(q))
})

// A typed name that doesn't exactly match an existing one creates a new var.
const exactMatch = computed<boolean>(() =>
    props.knownNames.some((n) => n === props.modelValue.trim()),
)
const showCreateRow = computed<boolean>(() =>
    props.modelValue.trim() !== '' && !exactMatch.value,
)

function reposition() {
    const el = inputRef.value
    if (!el) return
    const rect = el.getBoundingClientRect()
    menuPos.value = { top: rect.bottom + 4, left: rect.left, width: rect.width }
}

function openMenu() {
    reposition()
    open.value = true
    activeIdx.value = -1
}

function close() {
    open.value = false
    activeIdx.value = -1
}

function onInput(event: Event) {
    emit('update:modelValue', (event.target as HTMLInputElement).value)
    if (!open.value) openMenu()
    else reposition()
    activeIdx.value = -1
}

function pick(name: string) {
    emit('pick', name)
    emit('update:modelValue', name)
    close()
}

function onKeydown(event: KeyboardEvent) {
    if (!open.value && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
        openMenu()
        return
    }
    if (event.key === 'ArrowDown') {
        event.preventDefault()
        activeIdx.value = Math.min(activeIdx.value + 1, matches.value.length - 1)
    } else if (event.key === 'ArrowUp') {
        event.preventDefault()
        activeIdx.value = Math.max(activeIdx.value - 1, 0)
    } else if (event.key === 'Enter') {
        if (activeIdx.value >= 0 && activeIdx.value < matches.value.length) {
            event.preventDefault()
            pick(matches.value[activeIdx.value]!)
        } else {
            close()
        }
    } else if (event.key === 'Escape') {
        close()
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

watch(() => props.modelValue, () => { if (open.value) void nextTick(reposition) })

onUnmounted(() => {
    document.removeEventListener('mousedown', onDocClick, { capture: true })
    window.removeEventListener('scroll', onReposition, true)
    window.removeEventListener('resize', onReposition)
})
</script>

<template>
    <div ref="rootRef" class="name-select">
        <input
            ref="inputRef"
            type="text"
            class="ns-input"
            :class="{ 'ns-input--open': open }"
            :value="modelValue"
            :placeholder="placeholder ?? 'my_variable'"
            autocomplete="off"
            spellcheck="false"
            @input="onInput"
            @focus="openMenu"
            @keydown="onKeydown"
        >

        <Teleport to="body">
            <div
                v-if="open && (matches.length > 0 || showCreateRow)"
                ref="menuRef"
                :style="{ top: `${menuPos.top}px`, left: `${menuPos.left}px`, width: `${menuPos.width}px` }"
                class="ns-menu"
                role="listbox"
            >
                <button
                    v-for="(name, idx) in matches"
                    :key="name"
                    :aria-selected="modelValue === name"
                    :class="{ 'ns-option--active': idx === activeIdx, 'ns-option--current': modelValue === name }"
                    class="ns-option"
                    role="option"
                    type="button"
                    @mouseenter="activeIdx = idx"
                    @click="pick(name)"
                >
                    <span class="ns-option-mark">{{ modelValue === name ? '✓' : '' }}</span>
                    <span class="ns-option-label">{{ name }}</span>
                    <span class="ns-option-tag">existing</span>
                </button>

                <div v-if="matches.length > 0 && showCreateRow" class="ns-divider" />

                <div v-if="showCreateRow" class="ns-create-hint">
                    <span class="ns-option-mark">✎</span>
                    <span class="ns-option-label">New variable: <strong>{{ modelValue.trim() }}</strong></span>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.name-select {
    position: relative;
    width: 100%;
}

.ns-input {
    width: 100%;
    padding: 6px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
    color: var(--text);
    font-size: 13px;
    font-family: inherit;
    box-sizing: border-box;
}
.ns-input:focus,
.ns-input--open {
    outline: none;
    border-color: var(--primary);
}

.ns-menu {
    position: fixed;
    z-index: 60;
    padding: 4px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
    max-height: 240px;
    overflow-y: auto;
}

.ns-option {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 6px 8px;
    border: none;
    border-radius: 4px;
    background: transparent;
    color: var(--text);
    font-family: inherit;
    font-size: 13px;
    text-align: left;
    cursor: pointer;
    transition: background 100ms;
}
.ns-option--active {
    background: var(--surface-2, #f4f5f6);
}
.ns-option--current {
    color: var(--primary);
}
.ns-option-mark {
    flex: 0 0 14px;
    text-align: center;
    color: var(--primary);
    font-size: 11px;
    line-height: 1;
}
.ns-option-label {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ns-option-tag {
    flex-shrink: 0;
    font-size: 10px;
    color: var(--text-3);
    text-transform: uppercase;
    letter-spacing: .04em;
}

.ns-divider {
    height: 1px;
    margin: 4px 0;
    background: var(--border);
}

.ns-create-hint {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    font-size: 12.5px;
    color: var(--text-3);
}
.ns-create-hint .ns-option-mark {
    color: var(--text-3);
}
.ns-create-hint strong {
    color: var(--text);
    font-weight: 600;
}
</style>
