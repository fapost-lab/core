import { usePage } from '@inertiajs/vue3'

export function useTranslations() {
    const page = usePage()

    function t(key) {
        const keys = key.split('.')
        let value = page.props.translations?.builder
        for (const k of keys) {
            value = value?.[k]
        }
        return value ?? key
    }

    return { t }
}
