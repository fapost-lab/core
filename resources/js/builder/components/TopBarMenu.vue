<script setup lang="ts">
import {onBeforeUnmount, ref, watch} from 'vue'

/**
 * A small single-choice menu for the top bar (theme, language), shaped like the console's dropdowns: a trigger
 * button and a list with a dot before the chosen item. The builder has no Tailwind, so the kit's dropdown cannot be
 * reused here.
 */
const props = defineProps<{
    label: string
    value: string
    options: ReadonlyArray<{ value: string, label: string }>
}>()

const emit = defineEmits<{ select: [value: string] }>()

const open = ref(false)
const root = ref<HTMLElement | null>(null)

function choose(value: string): void {
    open.value = false
    if (value !== props.value) {
        emit('select', value)
    }
}

function onPointerDown(event: PointerEvent): void {
    if (root.value && !root.value.contains(event.target as Node)) {
        open.value = false
    }
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        open.value = false
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        document.addEventListener('pointerdown', onPointerDown)
        document.addEventListener('keydown', onKeydown)
    } else {
        document.removeEventListener('pointerdown', onPointerDown)
        document.removeEventListener('keydown', onKeydown)
    }
})

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onPointerDown)
    document.removeEventListener('keydown', onKeydown)
})
</script>

<template>
    <div ref="root" class="menu">
        <button
            type="button"
            class="menu-trigger"
            :title="label"
            :aria-label="label"
            aria-haspopup="menu"
            :aria-expanded="open"
            @click="open = !open"
        >
            <slot />
        </button>
        <ul v-if="open" class="menu-list" role="menu">
            <li v-for="option in options" :key="option.value">
                <button
                    type="button"
                    class="menu-item"
                    role="menuitemradio"
                    :aria-checked="option.value === value"
                    @click="choose(option.value)"
                >
                    <span class="menu-dot" :class="{ on: option.value === value }" aria-hidden="true" />
                    {{ option.label }}
                </button>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.menu { position: relative; }
.menu-trigger {
    display: flex;
    align-items: center;
    gap: 5px;
    height: 28px;
    padding: 0 8px;
    border-radius: var(--radius);
    border: 1px solid transparent;
    background: transparent;
    color: var(--text-2);
    font-family: var(--font-sans);
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all .15s;
}
.menu-trigger:hover,
.menu-trigger[aria-expanded="true"] {
    background: var(--surface-2);
    color: var(--text);
}
.menu-list {
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    z-index: 30;
    min-width: 140px;
    padding: 4px;
    list-style: none;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-tab);
}
.menu-item {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 6px 8px;
    border: none;
    border-radius: 6px;
    background: transparent;
    color: var(--text);
    font-family: var(--font-sans);
    font-size: 13px;
    text-align: left;
    cursor: pointer;
}
.menu-item:hover { background: var(--surface-2); }
.menu-dot {
    width: 6px;
    height: 6px;
    flex-shrink: 0;
    border-radius: 50%;
}
.menu-dot.on { background: var(--text); }
</style>
