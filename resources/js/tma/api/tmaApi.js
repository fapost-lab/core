import { useTelegram } from '@tma/composables/useTelegram'

const BASE = '/tma/api'

async function request(method, url, body = null) {
    const { initData } = useTelegram()

    const options = {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-Telegram-Init-Data': initData,
        },
    }

    if (body) {
        options.body = JSON.stringify(body)
    }

    const response = await fetch(`${BASE}${url}`, options)

    if (!response.ok) {
        const err = await response.json().catch(() => ({}))

        throw Object.assign(new Error(err.message ?? 'Request failed'), {
            status: response.status,
        })
    }

    return response.json()
}

export const fetchFormDefinition = (formId) => request('GET', `/forms/${formId}`)
export const submitForm = (formId, answers) => request('POST', `/forms/${formId}/submit`, { answers })
