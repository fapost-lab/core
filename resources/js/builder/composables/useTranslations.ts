import { usePage } from '@inertiajs/vue3'

export function useTranslations() {
    const page = usePage()

    function t(key: string): string {
        const keys = key.split('.')
        let value: unknown = (page.props as Record<string, unknown>)['translations']
        value = (value as Record<string, unknown> | undefined)?.['builder']
        for (const k of keys) {
            value = (value as Record<string, unknown> | undefined)?.[k]
        }
        return (value as string | undefined) ?? key
    }

    return { t }
}
