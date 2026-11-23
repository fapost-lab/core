<script setup lang="ts">
import {ref} from 'vue'
import NodePalette from '../NodePalette.vue'

/**
 * The graph's "+": opens the node palette, which inserts the chosen type after `afterNodeId` on `handle` through the
 * store's `insertNode` — the same operation the tree's insert point runs. An empty `afterNodeId` adds the first node.
 */
const props = defineProps({
    afterNodeId: { type: String, default: '' },
    handle:      { type: String, default: 'default' },
    title:       { type: String, required: true },
    /** Shown next to the "+" (a free handle's name); none on an edge. */
    label:       { type: String as () => string | null, default: null },
})

interface PalettePos { top: number; left: number; anchorTop?: number }

const open       = ref(false)
const buttonRef  = ref<HTMLButtonElement | null>(null)
const palettePos = ref<PalettePos>({ top: 0, left: 0 })

function toggle(): void {
    if (!open.value && buttonRef.value) {
        const rect = buttonRef.value.getBoundingClientRect()
        palettePos.value = { top: rect.bottom + 6, left: rect.left + rect.width / 2, anchorTop: rect.top }
    }
    open.value = !open.value
}

function close(): void {
    open.value = false
}
</script>

<template>
    <button
        ref="buttonRef"
        type="button"
        class="graph-insert nodrag nopan"
        :class="{ 'graph-insert--labelled': props.label !== null, 'graph-insert--open': open }"
        :title="title"
        :aria-label="title"
        @click.stop="toggle"
    >
        <span class="graph-insert-plus" aria-hidden="true">+</span>
        <span v-if="props.label !== null" class="graph-insert-label">{{ props.label }}</span>
    </button>

    <Teleport to="body">
        <NodePalette
            v-if="open"
            :after-node-id="afterNodeId"
            :handle="handle"
            :palette-pos="palettePos"
            @select="close"
            @close="close"
        />
    </Teleport>
</template>

<style scoped>
.graph-insert {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    min-width: 20px;
    height: 20px;
    padding: 0 5px;
    border-radius: 999px;
    border: 1px solid var(--border-2);
    background: var(--surface);
    color: var(--text-3);
    font-family: var(--font-sans);
    font-size: 11.5px;
    line-height: 1;
    cursor: pointer;
    pointer-events: all;
    transition: color .12s, border-color .12s, background .12s;
}
.graph-insert:hover,
.graph-insert:focus-visible,
.graph-insert--open {
    color: var(--primary);
    border-color: var(--primary);
    background: var(--primary-bg);
}
.graph-insert:focus-visible {
    outline: 2px solid color-mix(in srgb, var(--primary) 35%, transparent);
    outline-offset: 1px;
}
.graph-insert--labelled {
    max-width: 100%;
    border-style: dashed;
}
.graph-insert-plus {
    font-size: 14px;
    font-weight: 600;
}
.graph-insert-label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>
