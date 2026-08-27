interface TelegramMainButton {
    setText(text: string): void
    show(): void
    hide(): void
    onClick(fn: () => void): void
    offClick(fn: () => void): void
}

interface TelegramWebApp {
    ready(): void
    expand(): void
    close(): void
    showAlert(message: string, callback?: () => void): void
    initData: string
    initDataUnsafe: Record<string, unknown>
    colorScheme: 'light' | 'dark'
    themeParams: Record<string, string>
    MainButton: TelegramMainButton
}

declare global {
    interface Window {
        Telegram?: { WebApp: TelegramWebApp }
    }
}

export function useTelegram() {
    const tg = window.Telegram?.WebApp

    function ready() {
        tg?.ready()
    }

    function expand() {
        tg?.expand()
    }

    function close() {
        tg?.close()
    }

    function showMainButton(text: string, onClick: () => void) {
        if (!tg?.MainButton) {
            return
        }

        tg.MainButton.offClick(onClick)
        tg.MainButton.setText(text)
        tg.MainButton.onClick(onClick)
        tg.MainButton.show()
    }

    function hideMainButton() {
        tg?.MainButton?.hide()
    }

    function showAlert(message: string) {
        tg?.showAlert(message)
    }

    const initData = tg?.initData ?? ''
    const initDataUnsafe = tg?.initDataUnsafe ?? {}
    const colorScheme = tg?.colorScheme ?? 'light'
    const themeParams = tg?.themeParams ?? {}

    return {
        ready,
        expand,
        close,
        showMainButton,
        hideMainButton,
        showAlert,
        initData,
        initDataUnsafe,
        colorScheme,
        themeParams,
    }
}
