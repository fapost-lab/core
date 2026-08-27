import type {Ref} from 'vue'

/**
 * Insertion helper for `<input>` / `<textarea>` elements bound through a
 * template ref. Inserts text at the current selection, falls back to the
 * end of the value when the input has never been focused.
 *
 * Returns a function that takes the snippet to insert. The caller is
 * responsible for emitting an `update:value` event on the parent so the
 * v-model contract stays intact — DOM `value` mutation alone wouldn't
 * propagate through Vue's reactivity.
 */
export function useInsertAtCursor(
    inputRef: Ref<HTMLInputElement | HTMLTextAreaElement | null>,
    onUpdate: (next: string) => void,
): (snippet: string) => void {
    return (snippet: string) => {
        const el = inputRef.value
        if (el === null) {
            // No focused element → just append. Rare path, e.g. picker
            // clicked before the input was ever interacted with.
            onUpdate(snippet)
            return
        }

        const value = el.value ?? ''
        const start = el.selectionStart ?? value.length
        const end   = el.selectionEnd ?? value.length

        const next = value.slice(0, start) + snippet + value.slice(end)
        onUpdate(next)

        // Restore caret right after the inserted snippet on next tick so
        // the user can keep typing without re-clicking the input.
        const newCaret = start + snippet.length
        requestAnimationFrame(() => {
            if (inputRef.value === el) {
                el.focus()
                el.setSelectionRange(newCaret, newCaret)
            }
        })
    }
}
