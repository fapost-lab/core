<script setup lang="ts">
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

const open        = ref(false)
const isCreating  = ref(false)
const draftName   = ref('')
const rootRef     = ref<HTMLElement | null>(null)
const triggerRef  = ref<HTMLButtonElement | null>(null)
const menuRef     = ref<HTMLElement | null>(null)
const createInput = ref<HTMLInputElement | null>(null)

interface MenuPos { top: number; left: number; width: number }
const menuPos = ref<MenuPos>({ top: 0, left: 0, width: 0 })

const hasKnown = computed(() => props.knownNames.length > 0)

function reposition() {
    const el = triggerRef.value
    if (!el) return
    const rect = el.getBoundingClientRect()
    menuPos.value = { top: rect.bottom + 4, left: rect.left, width: rect.width }
}

function toggle() {
    if (isCreating.value) return
    if (!hasKnown.value) {
        void startCreate()
        return
    }
    if (!open.value) reposition()
    open.value = !open.value
}

function close() { open.value = false }

function pick(name: string) {
    emit('pick', name)
    emit('update:modelValue', name)
    close()
}

async function startCreate() {
    open.value    = false
    isCreating.value = true
    draftName.value  = props.modelValue
    await nextTick()
    createInput.value?.focus()
    createInput.value?.select()
}

function commitCreate() {
    emit('update:modelValue', draftName.value)
    isCreating.value = false
}

function cancelCreate() {
    isCreating.value = false
}

function onCreateKeydown(event: KeyboardEvent) {
    if (event.key === 'Enter')  { event.preventDefault(); commitCreate() }
    if (event.key === 'Escape') { event.preventDefault(); cancelCreate() }
}

function onCreateInput(event: Event) {
    draftName.value = (event.target as HTMLInputElement).value
    emit('update:modelValue', draftName.value)
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
    <div ref="rootRef" class="name-select">
        <!-- Inline text input when typing a new name -->
        <input
            v-if="isCreating"
            ref="createInput"
            type="text"
            class="ns-input"
            :value="draftName"
            :placeholder="placeholder ?? 'my_variable'"
            autocomplete="off"
            spellcheck="false"
            @input="onCreateInput"
            @keydown="onCreateKeydown"
            @blur="commitCreate"
        >

        <!-- Trigger button + dropdown -->
        <template v-else>
            <button
                ref="triggerRef"
                type="button"
                class="ns-trigger"
                :class="{ 'ns-trigger--placeholder': modelValue === '', 'ns-trigger--open': open }"
                :aria-expanded="open"
                @click="toggle"
            >
                <span class="ns-trigger-label">{{ modelValue || (placeholder ?? 'my_variable') }}</span>
                <span class="ns-trigger-caret" :class="{ 'ns-trigger-caret--open': open }">▾</span>
            </button>

            <Teleport to="body">
                <div
                    v-if="open"
                    ref="menuRef"
                    :style="{ top: `${menuPos.top}px`, left: `${menuPos.left}px`, width: `${menuPos.width}px` }"
                    class="ns-menu"
                    role="listbox"
                >
                    <template v-if="hasKnown">
                        <button
                            v-for="name in knownNames"
                            :key="name"
                            :aria-selected="modelValue === name"
                            :class="{ 'ns-option--active': modelValue === name }"
                            class="ns-option"
                            role="option"
                            type="button"
                            @click="pick(name)"
                        >
                            <span class="ns-option-mark">{{ modelValue === name ? '✓' : '' }}</span>
                            <span class="ns-option-label">{{ name }}</span>
                        </button>
                        <div class="ns-divider" />
                    </template>

                    <button
                        class="ns-option ns-option--create"
                        type="button"
                        @click="startCreate"
                    >
                        <span class="ns-option-mark">✎</span>
                        <span class="ns-option-label">Type new name…</span>
                    </button>
                </div>
            </Teleport>
        </template>
    </div>
</template>

<style scoped>
.name-select {
    position: relative;
    width: 100%;
}

.ns-trigger,
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
.ns-trigger:focus,
.ns-trigger--open {
    outline: none;
    border-color: var(--primary);
}

.ns-trigger {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    cursor: pointer;
    text-align: left;
}
.ns-trigger--placeholder .ns-trigger-label {
    color: var(--text-3);
}
.ns-trigger-label {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.ns-trigger-caret {
    color: var(--text-3);
    font-size: 11px;
    transition: transform 120ms;
    flex-shrink: 0;
}
.ns-trigger-caret--open {
    transform: rotate(180deg);
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
.ns-option:hover {
    background: var(--surface-2, #f4f5f6);
}
.ns-option--active {
    background: var(--primary-bg, rgba(0, 0, 0, 0.04));
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
.ns-option--create {
    color: var(--primary);
}
.ns-option--create:hover {
    background: var(--primary-bg, rgba(0, 0, 0, 0.04));
}
.ns-option--create .ns-option-mark {
    color: var(--primary);
    font-weight: 600;
}

.ns-divider {
    height: 1px;
    margin: 4px 0;
    background: var(--border);
}
</style>
