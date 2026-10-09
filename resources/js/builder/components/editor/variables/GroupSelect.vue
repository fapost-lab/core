<script setup lang="ts">
import {computed, nextTick, onUnmounted, ref, watch} from 'vue'

const props = defineProps<{
    modelValue:  string | null
    knownGroups: string[]
}>()

const emit = defineEmits<{
    (e: 'update:modelValue', value: string | null): void
    (e: 'create', value: string): void
}>()

const open        = ref(false)
const isCreating  = ref(false)
const draftName   = ref('')
const rootRef     = ref<HTMLElement | null>(null)
const triggerRef = ref<HTMLButtonElement | null>(null)
const menuRef = ref<HTMLElement | null>(null)
const createInput = ref<HTMLInputElement | null>(null)

interface MenuPos {
    top: number;
    left: number;
    width: number
}

const menuPos = ref<MenuPos>({top: 0, left: 0, width: 0})

const triggerLabel = computed<string>(() => props.modelValue ?? '(No group)')
const hasGroups    = computed<boolean>(() => props.knownGroups.length > 0)

function reposition() {
    const el = triggerRef.value
    if (!el) return
    const rect = el.getBoundingClientRect()
    menuPos.value = {
        top: rect.bottom + 4,
        left: rect.left,
        width: rect.width,
    }
}

function toggle() {
    if (isCreating.value) return
    if (!open.value) reposition()
    open.value = !open.value
}

function close() {
    open.value = false
}

function pick(value: string | null) {
    emit('update:modelValue', value)
    close()
}

async function startCreate() {
    open.value      = false
    isCreating.value = true
    draftName.value = ''
    await nextTick()
    createInput.value?.focus()
}

function commitCreate() {
    const name = draftName.value.trim()
    if (name === '') {
        cancelCreate()
        return
    }
    emit('create', name)
    emit('update:modelValue', name)
    isCreating.value = false
    draftName.value  = ''
}

function cancelCreate() {
    isCreating.value = false
    draftName.value  = ''
}

function onCreateKeydown(event: KeyboardEvent) {
    if (event.key === 'Enter') {
        event.preventDefault()
        commitCreate()
    } else if (event.key === 'Escape') {
        event.preventDefault()
        cancelCreate()
    }
}

function onDocClick(e: MouseEvent) {
    const target = e.target as Node
    if (rootRef.value?.contains(target)) return
    if (menuRef.value?.contains(target)) return
    close()
}

function onReposition() {
    if (open.value) reposition()
}

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
    <div ref="rootRef" class="group-select">
        <input
            v-if="isCreating"
            ref="createInput"
            v-model="draftName"
            type="text"
            class="group-create-input"
            placeholder="New group name"
            @keydown="onCreateKeydown"
            @blur="commitCreate"
        >

        <template v-else>
            <button
                ref="triggerRef"
                type="button"
                class="group-trigger"
                :class="{ 'group-trigger--placeholder': modelValue === null, 'group-trigger--open': open }"
                :aria-expanded="open"
                @click="toggle"
            >
                <span class="group-trigger-label">{{ triggerLabel }}</span>
                <span class="group-trigger-caret" :class="{ 'group-trigger-caret--open': open }">▾</span>
            </button>

            <Teleport to="body">
                <div
                    v-if="open"
                    ref="menuRef"
                    :style="{
                        top:   `${menuPos.top}px`,
                        left:  `${menuPos.left}px`,
                        width: `${menuPos.width}px`,
                    }"
                    class="group-menu"
                    role="listbox"
                >
                    <button
                        :aria-selected="modelValue === null"
                        :class="{ 'group-option--active': modelValue === null }"
                        class="group-option"
                        role="option"
                        type="button"
                        @click="pick(null)"
                    >
                        <span class="group-option-mark">{{ modelValue === null ? '✓' : '' }}</span>
                        <span class="group-option-label group-option-label--muted">(No group)</span>
                    </button>

                    <div v-if="hasGroups" class="group-divider"/>

                    <button
                        v-for="group in props.knownGroups"
                        :key="group"
                        :aria-selected="modelValue === group"
                        :class="{ 'group-option--active': modelValue === group }"
                        class="group-option"
                        role="option"
                        type="button"
                        @click="pick(group)"
                    >
                        <span class="group-option-mark">{{ modelValue === group ? '✓' : '' }}</span>
                        <span class="group-option-label">{{ group }}</span>
                    </button>

                    <div class="group-divider"/>

                    <button
                        class="group-option group-option--create"
                        type="button"
                        @click="startCreate"
                    >
                        <span class="group-option-mark">+</span>
                        <span class="group-option-label">Create new group…</span>
                    </button>
                </div>
            </Teleport>
        </template>
    </div>
</template>

<style scoped>
.group-select {
    position: relative;
    width: 100%;
}

.group-trigger,
.group-create-input {
    width: 100%;
    padding: 6px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
    color: var(--text);
    font-size: 13px;
    font-family: inherit;
}
.group-create-input:focus,
.group-trigger:focus,
.group-trigger--open {
    outline: none;
    border-color: var(--primary);
}

.group-trigger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    cursor: pointer;
    text-align: left;
}
.group-trigger--placeholder .group-trigger-label {
    color: var(--text-3);
}
.group-trigger-label {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.group-trigger-caret {
    color: var(--text-3);
    font-size: 11px;
    transition: transform 120ms;
}
.group-trigger-caret--open {
    transform: rotate(180deg);
}

.group-menu {
    position: fixed;
    z-index: 60;
    padding: 4px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-pop);
    max-height: 240px;
    overflow-y: auto;
}

.group-option {
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
.group-option:hover {
    background: var(--surface-2);
}
.group-option--active {
    background: var(--primary-bg);
    color: var(--text);
}
.group-option-mark {
    flex: 0 0 14px;
    text-align: center;
    color: var(--primary);
    font-size: 11px;
    line-height: 1;
}
.group-option-label {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.group-option-label--muted {
    color: var(--text-2);
    font-style: italic;
}
.group-option--create {
    color: var(--primary);
}
.group-option--create:hover {
    background: var(--primary-bg);
}
.group-option--create .group-option-mark {
    color: var(--primary);
    font-weight: 600;
}

.group-divider {
    height: 1px;
    margin: 4px 0;
    background: var(--border);
}
</style>
