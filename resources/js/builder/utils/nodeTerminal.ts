/**
 * A node "must be last" when it has no default-handle exit path of its own
 * and is by contract the final step in the chain. Reordering must never
 * push another node behind it, otherwise the published flow becomes
 * unreachable past the terminator.
 *
 * Currently covers:
 *   - `end` nodes: hard terminator regardless of config.
 *   - `send_message` with inline buttons: exits via per-button branches.
 *   - `send_message` with reply keyboard: continuation is handled by a
 *     separate flow via keyword/trigger matching, this flow ends here.
 */
export interface TerminalCheckInput {
    type: string
    config?: Record<string, unknown> | null
}

export function nodeMustBeLast(node: TerminalCheckInput): boolean {
    if (node.type === 'end') return true
    if (node.type !== 'send_message') return false

    const config = (node.config ?? {}) as Record<string, unknown>
    if (config.content_type !== 'text_with_keyboard') return false

    const mode = (config.keyboard_mode ?? 'inline') as string
    if (mode === 'reply') return true

    // Dynamic keyboard generates per-button handles at runtime — treat as
    // populated regardless of the static `buttons` array.
    if (config.dynamic_buttons !== null && config.dynamic_buttons !== undefined) return true

    const buttons = Array.isArray(config.buttons) ? (config.buttons as unknown[]) : []
    return buttons.length > 0
}
