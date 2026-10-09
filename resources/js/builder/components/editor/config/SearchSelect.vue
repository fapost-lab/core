<script setup lang="ts">
/**
 * Searchable single/multi select over {value,label} options, with an optional
 * "create" affordance for free values (used for tags). The trigger shows chips
 * (multi) or the current label (single); opening reveals a filter input and a
 * teleported, viewport-anchored option list. Selected values are always emitted
 * as a string[] — single mode just keeps it at length 0..1.
 */
import {computed, nextTick, onUnmounted, ref, watch} from 'vue'

interface Option { value: string; label: string }

const props = withDefaults(defineProps<{
    modelValue:   string[]
    options:      Option[]
    multiple?:    boolean
    allowCreate?: boolean
    placeholder?: string
    emptyText?:   string
}>(), {
    multiple: false,
    allowCreate: false,
    placeholder: 'Select…',
    emptyText: 'No options',
})

const emit = defineEmits<{
    (e: 'update:modelValue', value: string[]): void
}>()

const open       = ref(false)
const query      = ref('')
const rootRef    = ref<HTMLElement | null>(null)
const triggerRef = ref<HTMLButtonElement | null>(null)
const menuRef    = ref<HTMLElement | null>(null)
const searchRef  = ref<HTMLInputElement | null>(null)

interface MenuPos { top: number; left: number; width: number }
const menuPos = ref<MenuPos>({top: 0, left: 0, width: 0})

const labelOf = (value: string): string =>
    props.options.find((o) => o.value === value)?.label ?? value

const selectedChips = computed<Option[]>(() => props.modelValue.map((v) => ({value: v, label: labelOf(v)})))

const triggerLabel = computed<string>(() => {
    if (props.modelValue.length === 0) {
        return props.placeholder
    }
    return labelOf(props.modelValue[0]!)
})

const filtered = computed<Option[]>(() => {
    const q = query.value.trim().toLowerCase()
    if (q === '') {
        return props.options
    }
    return props.options.filter((o) => o.label.toLowerCase().includes(q))
})

// Offer a "create" row when allowCreate and the typed value matches no existing
// option (by label) and isn't already selected.
const creatable = computed<string | null>(() => {
    const q = query.value.trim()
    if (!props.allowCreate || q === '') {
        return null
    }
    const exists = props.options.some((o) => o.label.toLowerCase() === q.toLowerCase())
    if (exists || props.modelValue.includes(q)) {
        return null
    }
    return q
})

function reposition() {
    const el = triggerRef.value
    if (!el) {
        return
    }
    const rect = el.getBoundingClientRect()
    menuPos.value = {top: rect.bottom + 4, left: rect.left, width: rect.width}
}

async function toggleOpen() {
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

function isSelected(value: string): boolean {
    return props.modelValue.includes(value)
}

function pick(value: string) {
    if (props.multiple) {
        const next = isSelected(value)
            ? props.modelValue.filter((v) => v !== value)
            : [...props.modelValue, value]
        emit('update:modelValue', next)
    } else {
        emit('update:modelValue', [value])
        open.value = false
    }
    query.value = ''
}

function removeChip(value: string) {
    emit('update:modelValue', props.modelValue.filter((v) => v !== value))
}

function commitCreate() {
    const v = creatable.value
    if (v === null) {
        return
    }
    pick(v)
}

function onDocClick(e: MouseEvent) {
    const target = e.target as Node
    if (rootRef.value?.contains(target) || menuRef.value?.contains(target)) {
        return
    }
    open.value = false
}

function onReposition() {
    if (open.value) {
        reposition()
    }
}

watch(open, (val) => {
    if (val) {
        document.addEventListener('mousedown', onDocClick, {capture: true})
        window.addEventListener('scroll', onReposition, true)
        window.addEventListener('resize', onReposition)
    } else {
        document.removeEventListener('mousedown', onDocClick, {capture: true})
        window.removeEventListener('scroll', onReposition, true)
        window.removeEventListener('resize', onReposition)
    }
})

onUnmounted(() => {
    document.removeEventListener('mousedown', onDocClick, {capture: true})
    window.removeEventListener('scroll', onReposition, true)
    window.removeEventListener('resize', onReposition)
})
</script>

<template>
    <div ref="rootRef" class="search-select">
        <button
            ref="triggerRef"
            type="button"
            class="ss-trigger"
            :class="{ 'ss-trigger--placeholder': modelValue.length === 0, 'ss-trigger--open': open }"
            @click="toggleOpen"
        >
            <span v-if="multiple && modelValue.length > 0" class="ss-chips">
                <span v-for="chip in selectedChips" :key="chip.value" class="ss-chip">
                    {{ chip.label }}
                    <span class="ss-chip-x" @click.stop="removeChip(chip.value)">×</span>
                </span>
            </span>
            <span v-else class="ss-trigger-label">{{ triggerLabel }}</span>
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
                    @keydown.enter.prevent="commitCreate"
                >

                <button
                    v-for="opt in filtered"
                    :key="opt.value"
                    type="button"
                    class="ss-option"
                    :class="{ 'ss-option--active': isSelected(opt.value) }"
                    role="option"
                    @click="pick(opt.value)"
                >
                    <span class="ss-option-mark">{{ isSelected(opt.value) ? '✓' : '' }}</span>
                    <span class="ss-option-label">{{ opt.label }}</span>
                </button>

                <button
                    v-if="creatable !== null"
                    type="button"
                    class="ss-option ss-option--create"
                    @click="commitCreate"
                >
                    <span class="ss-option-mark">+</span>
                    <span class="ss-option-label">Create “{{ creatable }}”</span>
                </button>

                <div v-if="filtered.length === 0 && creatable === null" class="ss-empty">{{ emptyText }}</div>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.search-select {
    position: relative;
    width: 100%;
}
.ss-trigger {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    min-height: 34px;
    padding: 5px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
    color: var(--text);
    font-size: 13px;
    font-family: inherit;
    cursor: pointer;
    text-align: left;
}
.ss-trigger--open,
.ss-trigger:focus {
    outline: none;
    border-color: var(--primary);
}
.ss-trigger--placeholder .ss-trigger-label {
    color: var(--text-3);
}
.ss-trigger-label {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ss-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    flex: 1;
}
.ss-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 1px 6px;
    border-radius: 10px;
    background: var(--surface-2);
    color: var(--text);
    font-size: 12px;
}
.ss-chip-x {
    cursor: pointer;
    color: var(--text-3);
    font-size: 13px;
    line-height: 1;
}
.ss-chip-x:hover {
    color: var(--rose);
}
.ss-caret {
    color: var(--text-3);
    font-size: 11px;
    transition: transform 120ms;
}
.ss-caret--open {
    transform: rotate(180deg);
}

.ss-menu {
    position: fixed;
    z-index: 60;
    padding: 4px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-pop);
    max-height: 260px;
    overflow-y: auto;
}
.ss-search {
    width: 100%;
    box-sizing: border-box;
    padding: 6px 8px;
    margin-bottom: 4px;
    border: 1px solid var(--border);
    border-radius: 4px;
    font-size: 13px;
    font-family: inherit;
}
.ss-search:focus {
    outline: none;
    border-color: var(--primary);
}
.ss-option {
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
}
.ss-option:hover {
    background: var(--surface-2);
}
.ss-option--active {
    background: var(--primary-bg);
}
.ss-option-mark {
    flex: 0 0 14px;
    text-align: center;
    color: var(--primary);
    font-size: 11px;
}
.ss-option-label {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ss-option--create {
    color: var(--primary);
}
.ss-empty {
    padding: 8px;
    color: var(--text-3);
    font-size: 12px;
    text-align: center;
}
</style>
