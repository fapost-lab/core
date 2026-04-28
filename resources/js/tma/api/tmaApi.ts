import type { FormAnswers, FormDefinition, FormSubmitResponse } from '@tma/dto/types'
import { useTelegram } from '@tma/composables/useTelegram'

const BASE = '/tma/api'

interface ApiError extends Error {
    status: number
}

async function request<T>(method: string, url: string, body: unknown = null): Promise<T> {
    const { initData } = useTelegram()

    const options: RequestInit & { headers: Record<string, string> } = {
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
        const err = await response.json().catch(() => ({})) as { message?: string }
        const error = Object.assign(new Error(err.message ?? 'Request failed'), {
            status: response.status,
        }) as ApiError

        throw error
    }

    return response.json() as Promise<T>
}

export const fetchFormDefinition = (formId: string): Promise<FormDefinition> =>
    request<FormDefinition>('GET', `/forms/${formId}`)

export const submitForm = (formId: string, answers: FormAnswers): Promise<FormSubmitResponse> =>
    request<FormSubmitResponse>('POST', `/forms/${formId}/submit`, { answers })
