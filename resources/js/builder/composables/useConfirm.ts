import { ref } from 'vue'

export interface ConfirmOptions {
    title?:        string
    message:       string
    confirmLabel?: string
    cancelLabel?:  string
    /** Renders the confirm button in destructive (rose) style. */
    danger?: boolean
}

interface ActiveConfirm extends Required<Omit<ConfirmOptions, 'danger' | 'title'>> {
    title:   string
    danger:  boolean
    resolve: (value: boolean) => void
}

// Module-level singleton so callers anywhere in the builder can call
// `useConfirm()` without prop drilling. A single `<ConfirmDialog />`
// mounted in the editor shell watches this ref.
const active = ref<ActiveConfirm | null>(null)

export function useConfirm() {
    function confirm(options: ConfirmOptions): Promise<boolean> {
        return new Promise<boolean>((resolve) => {
            // If another confirm is already open, reject it as cancelled
            // before opening the next one so the caller doesn't hang.
            if (active.value) {
                active.value.resolve(false)
            }

            active.value = {
                title:        options.title        ?? 'Confirm',
                message:      options.message,
                confirmLabel: options.confirmLabel ?? 'OK',
                cancelLabel:  options.cancelLabel  ?? 'Cancel',
                danger:       options.danger       ?? false,
                resolve,
            }
        })
    }

    function resolveActive(value: boolean) {
        const current = active.value
        if (!current) return
        active.value = null
        current.resolve(value)
    }

    return { active, confirm, resolveActive }
}
