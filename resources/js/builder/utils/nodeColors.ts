export interface NodeColorScheme {
    bg: string
    color: string
    icon: string
}

export const NODE_TYPE_COLORS: Record<string, NodeColorScheme> = {
    send_message:  { bg: 'var(--sky-bg)',     color: 'var(--sky)',     icon: '✉' },
    input:         { bg: 'var(--sage-bg)',    color: 'var(--sage)',    icon: '⌨' },
    condition:     { bg: 'var(--amber-bg)',   color: 'var(--amber)',   icon: '⇀' },
    delay:         { bg: '#f0edf8',           color: '#7060a8',        icon: '⏱' },
    webhook:       { bg: 'var(--sky-bg)',     color: 'var(--sky)',     icon: '⇡' },
    set_attribute: { bg: 'var(--sage-bg)',    color: 'var(--sage)',    icon: '✎' },
    switch:        { bg: 'var(--amber-bg)',   color: 'var(--amber)',   icon: '⇌' },
    end:           { bg: 'var(--rose-bg)',    color: 'var(--rose)',    icon: '■' },
    _default:      { bg: 'var(--surface-2)', color: 'var(--text-2)', icon: '○' },
}

export function nodeColors(type: string): NodeColorScheme {
    return NODE_TYPE_COLORS[type] ?? NODE_TYPE_COLORS['_default']!
}
