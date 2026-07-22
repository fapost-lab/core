export interface NodeColorScheme {
    bg: string
    color: string
    icon: string
}

// Emoji-based glyphs are intentionally distinct per node type — the previous
// minimalist Unicode arrows (⇀ / ⇌ / ⇡) blended into each other and forced
// authors to read the label every time. Emoji render at a meaningful size,
// have unique silhouettes, and are colour-aware on every modern OS.
//
// When adding a new node type: pick an emoji that's recognisable at small
// sizes (no overly detailed glyphs) and not already used. Keep the bg/color
// pair aligned with semantic colour groups:
//   sky    — outbound / messaging
//   sage   — input / data capture / state mutation
//   amber  — branching / control flow
//   plum   — timing / waiting
//   rose   — terminal / errors
//   slate  — utility / system
export const NODE_TYPE_COLORS: Record<string, NodeColorScheme> = {
    // Messaging / outbound
    send_message:  { bg: 'var(--sky-bg)',    color: 'var(--sky)',    icon: '💬' },

    // Input / data capture / mutation
    input:         { bg: 'var(--sage-bg)',   color: 'var(--sage)',   icon: '❓' },
    assign:        { bg: 'var(--sage-bg)',   color: 'var(--sage)',   icon: '📝' },
    set_tag:       { bg: 'var(--sage-bg)',   color: 'var(--sage)',   icon: '🏷️' },

    // Control flow / branching
    condition:     { bg: 'var(--amber-bg)',  color: 'var(--amber)',  icon: '🔀' },
    branch:        { bg: 'var(--amber-bg)',  color: 'var(--amber)',  icon: '🔀' },

    // Timing
    delay:         { bg: '#f0edf8',          color: '#7060a8',       icon: '⏱️' },

    // Looping (control flow — iteration)
    loop:          { bg: 'var(--amber-bg)',  color: 'var(--amber)',  icon: '🔁' },
    loop_end:      { bg: 'var(--amber-bg)',  color: 'var(--amber)',  icon: '🔚' },

    // External / network
    call:          { bg: 'var(--sky-bg)',    color: 'var(--sky)',    icon: '🌐' },
    emit_event:    { bg: 'var(--sky-bg)',    color: 'var(--sky)',    icon: '📡' },

    // Knowledge / RAG
    rag_query:     { bg: '#fff4e6',          color: '#c2570c',       icon: '🧠' },

    // Composition
    subflow:       { bg: '#eef2ff',          color: '#4f46e5',       icon: '🪆' },

    // Access control / branching on auth result
    auth_request:  { bg: 'var(--amber-bg)',  color: 'var(--amber)',  icon: '🔐' },

    // Notify (staff / contacts)
    notify:        { bg: '#eef4ff',          color: '#3538cd',       icon: '🔔' },

    // Terminal
    end:           { bg: 'var(--rose-bg)',   color: 'var(--rose)',   icon: '🛑' },

    _default:      { bg: 'var(--surface-2)', color: 'var(--text-2)', icon: '⚙️' },
}

export function nodeColors(type: string): NodeColorScheme {
    return NODE_TYPE_COLORS[type] ?? NODE_TYPE_COLORS['_default']!
}
