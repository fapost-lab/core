<script setup lang="ts">
import {computed, nextTick, onUnmounted, ref, watch} from 'vue'
import VariablePicker from '../VariablePicker.vue'
import {useInsertAtCursor} from '@builder/composables/useInsertAtCursor'

/**
 * Single tag editor for the set_tag node: a free-text input that autocompletes
 * against the tenant's existing tags and lets the author insert {{variables}}
 * for dynamically-computed tags. New (unmatched) values are allowed — the input
 * never constrains to the suggestion list.
 */
const props = defineProps<{
    modelValue: string
    knownTags:  string[]
}>()

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void
}>()

const inputRef   = ref<HTMLInputElement | null>(null)
const rootRef    = ref<HTMLElement | null>(null)
const menuRef     = ref<HTMLElement | null>(null)
const open        = ref(false)

interface MenuPos { top: number; left: number; width: number }

const menuPos = ref<MenuPos>({top: 0, left: 0, width: 0})

const insert = useInsertAtCursor(inputRef, (next) => emit('update:modelValue', next))

// Suggestions: known tags containing the typed text (case-insensitive),
// excluding an exact match (nothing to suggest once it's already typed).
const suggestions = computed<string[]>(() => {
    const query = props.modelValue.trim().toLowerCase()
    return props.knownTags
        .filter((tag) => tag.toLowerCase() !== query && (query === '' || tag.toLowerCase().includes(query)))
        .slice(0, 8)
})

function reposition() {
    const el = inputRef.value
    if (!el) {
        return
    }
    const rect = el.getBoundingClientRect()
    menuPos.value = {top: rect.bottom + 4, left: rect.left, width: rect.width}
}

function openMenu() {
    if (suggestions.value.length === 0) {
        open.value = false
        return
    }
    reposition()
    open.value = true
}

function onInput(event: Event) {
    emit('update:modelValue', (event.target as HTMLInputElement).value)
    nextTick(openMenu)
}

function pick(tag: string) {
    emit('update:modelValue', tag)
    open.value = false
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
    <div ref="rootRef" class="tag-input">
        <div class="tag-input-row">
            <input
                ref="inputRef"
                :value="modelValue"
                type="text"
                class="field-input tag-input-field"
                placeholder="tag or {{flow.variable}}"
                @input="onInput"
                @focus="openMenu"
            >
            <VariablePicker class="tag-input-picker" @select="insert"/>
        </div>

        <Teleport to="body">
            <div
                v-if="open"
                ref="menuRef"
                :style="{ top: `${menuPos.top}px`, left: `${menuPos.left}px`, width: `${menuPos.width}px` }"
                class="tag-menu"
                role="listbox"
            >
                <button
                    v-for="tag in suggestions"
                    :key="tag"
                    type="button"
                    class="tag-option"
                    role="option"
                    @click="pick(tag)"
                >{{ tag }}</button>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.tag-input {
    flex: 1;
    min-width: 0;
}
.tag-input-row {
    display: flex;
    align-items: center;
    gap: 6px;
}
.tag-input-field {
    flex: 1;
    min-width: 0;
}
.tag-input-picker {
    flex-shrink: 0;
}

.tag-menu {
    position: fixed;
    z-index: 60;
    padding: 4px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-pop);
    max-height: 220px;
    overflow-y: auto;
}
.tag-option {
    display: block;
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
.tag-option:hover {
    background: var(--surface-2);
}
</style>
