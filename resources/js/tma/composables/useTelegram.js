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

    function showMainButton(text, onClick) {
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

    function showAlert(message) {
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
