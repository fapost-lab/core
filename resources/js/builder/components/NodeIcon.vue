<script setup lang="ts">
import {computed} from 'vue'

const props = defineProps({
    type: { type: String, required: true },
    /**
     * Stroke width — 1.5 keeps lines crisp at 16-24px box sizes; consumers
     * needing larger renderings (config-panel header at 28px) can pass a
     * thicker stroke to compensate.
     */
    stroke: { type: Number, default: 1.7 },
})

// Custom monochrome line-style icons. Each case renders inside a 24x24
// viewBox with `stroke="currentColor"` so the glyph picks up the parent
// container's `color` CSS — same colour rules as before, no rainbow emoji.
//
// When adding a new node type: 24x24 viewBox, fill="none", stroke based,
// keep silhouette readable down to ~16px. Avoid >2 sub-paths per icon.
const paths = computed<string>(() => SHAPES[props.type] ?? SHAPES._default)

const SHAPES: Record<string, string> = {
    // ── send_message — chat bubble with reply tail ──
    send_message: `
        <path d="M4 6.5h16v9.5H10l-3.5 3v-3H4z" />
        <path d="M8 11h8M8 8.5h6" />
    `,

    // ── input — prompt cursor + text line ──
    input: `
        <path d="M3.5 10.5v-3a1.5 1.5 0 0 1 1.5-1.5h2" />
        <path d="M3.5 13.5v3a1.5 1.5 0 0 0 1.5 1.5h2" />
        <path d="M9 12h11M16 8.5l3.5 3.5-3.5 3.5" />
    `,

    // ── condition / branch — fork with two arrows ──
    condition: `
        <path d="M12 4v6" />
        <path d="M12 10l-5 4v6" />
        <path d="M12 10l5 4v6" />
        <circle cx="12" cy="4" r="1.4" fill="currentColor" stroke="none" />
    `,
    branch: `
        <path d="M12 4v6" />
        <path d="M12 10l-5 4v6" />
        <path d="M12 10l5 4v6" />
        <circle cx="12" cy="4" r="1.4" fill="currentColor" stroke="none" />
    `,

    // ── delay — clock with hand ──
    delay: `
        <circle cx="12" cy="12" r="7.5" />
        <path d="M12 8v4.5l3 1.8" />
    `,

    // ── assign — clipboard with equals ──
    assign: `
        <rect x="6" y="5" width="12" height="15" rx="1.5" />
        <path d="M9 5h6v-1a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1z" />
        <path d="M9 11.5h6M9 14.5h6" />
    `,

    // ── call — outgoing arrow with planet ring ──
    call: `
        <circle cx="12" cy="12" r="7.5" />
        <path d="M5 9.5c4 1.5 10 1.5 14 0M5 14.5c4-1.5 10-1.5 14 0" />
        <path d="M12 4.5c-2.5 2-2.5 13 0 15M12 4.5c2.5 2 2.5 13 0 15" />
    `,

    // ── emit_event — radiating broadcast ──
    emit_event: `
        <circle cx="12" cy="12" r="2" fill="currentColor" stroke="none" />
        <path d="M8 8a5.5 5.5 0 0 0 0 8M16 8a5.5 5.5 0 0 1 0 8" />
        <path d="M5 5a10 10 0 0 0 0 14M19 5a10 10 0 0 1 0 14" />
    `,

    // ── rag_query — book with search ──
    rag_query: `
        <path d="M5 4h9a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3z" />
        <path d="M5 17a3 3 0 0 1 3-3h9" />
        <circle cx="13" cy="11" r="2" />
        <path d="M14.5 12.5l1.8 1.8" />
    `,

    // ── subflow — nested squares ──
    subflow: `
        <rect x="3.5" y="3.5" width="17" height="17" rx="2" />
        <rect x="7" y="7" width="10" height="10" rx="1.5" />
        <rect x="10" y="10" width="4" height="4" rx="0.6" fill="currentColor" stroke="none" />
    `,

    // ── set_tag — price tag with hole ──
    set_tag: `
        <path d="M4 12.5l8-8H19v6.5L11 19z" />
        <circle cx="15" cy="9" r="1.3" fill="currentColor" stroke="none" />
    `,

    // ── notify — bell ──
    notify: `
        <path d="M12 4a5 5 0 0 0-5 5c0 5-2 6-2 6h14s-2-1-2-6a5 5 0 0 0-5-5z" />
        <path d="M10 19a2 2 0 0 0 4 0" />
    `,

    // ── auth_request — shield with check ──
    auth_request: `
        <path d="M12 3.5l7 2.5v5c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9v-5z" />
        <path d="M9 12l2 2 4-4" />
    `,

    // ── end — filled stop circle ──
    end: `
        <circle cx="12" cy="12" r="8" />
        <rect x="9" y="9" width="6" height="6" rx="1" fill="currentColor" stroke="none" />
    `,

    // ── comment — note sheet with a folded corner ──
    comment: `
        <path d="M5 4.5h9l5 5v10H5z" />
        <path d="M14 4.5v5h5" />
        <path d="M8 12.5h7M8 15.5h5" />
    `,

    // ── default — generic gear ──
    _default: `
        <circle cx="12" cy="12" r="3" />
        <path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1" />
    `,
}
</script>

<template>
    <svg
        class="node-icon-svg"
        viewBox="0 0 24 24"
        fill="none"
        :stroke-width="stroke"
        stroke-linecap="round"
        stroke-linejoin="round"
        v-html="paths"
    />
</template>

<style scoped>
.node-icon-svg {
    width: 100%;
    height: 100%;
    /* Padding inside the rounded plate makes the glyph breathe — caller
       sets the box size via parent .palette-icon / .node-type-icon /
       .node-icon, the svg fills it. */
    padding: 14%;
    box-sizing: border-box;
    color: currentColor;
    stroke: currentColor;
    flex-shrink: 0;
}
</style>
