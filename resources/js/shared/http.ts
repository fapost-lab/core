export function getCookie(name: string): string {
    const raw =
        document.cookie
            .split('; ')
            .find((row) => row.startsWith(`${name}=`))
            ?.split('=')
            .slice(1)
            .join('=') ?? ''

    if (!raw) {
        return ''
    }

    try {
        return decodeURIComponent(raw)
    } catch {
        return raw
    }
}

/**
 * Returns headers required for Laravel CSRF protection.
 */
export function xsrfHeaders(): Record<string, string> {
    return {
        'X-Requested-With': 'XMLHttpRequest',
        'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'),
    }
}
