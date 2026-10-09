/** The one-off messages a response carries (`flash` in HandleInertiaRequests), as the toasts to show for them. */
export interface FlashMessages {
    success?: string | null
    error?: string | null
}

export interface FlashToast {
    kind: 'success' | 'error'
    message: string
}

/** Errors first, then successes; a blank message is no message. */
export function toastsFor(flash: FlashMessages | null | undefined): FlashToast[] {
    const toasts: FlashToast[] = []

    if (flash?.error) {
        toasts.push({ kind: 'error', message: flash.error })
    }

    if (flash?.success) {
        toasts.push({ kind: 'success', message: flash.success })
    }

    return toasts
}
