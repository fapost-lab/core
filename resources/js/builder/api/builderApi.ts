import type {BuilderTriggerPayload, FlowDefinition, NodeTypePayload, ValidationResult,} from '@builder/dto/types'
import {xsrfHeaders} from '@shared/http'

const BASE = '/builder'

export interface ApiError extends Error {
    status: number
    body: unknown
}

async function request<T>(method: string, url: string, body: unknown = null): Promise<T> {
    const options: RequestInit & { headers: Record<string, string> } = {
        method,
        headers: {
            'Content-Type': 'application/json',
            ...xsrfHeaders(),
        },
    }

    if (body) {
        options.body = JSON.stringify(body)
    }

    const res = await fetch(`${BASE}${url}`, options)

    if (!res.ok) {
        const error = await res.json().catch(() => ({})) as { message?: string }
        const err = Object.assign(
            new Error(typeof error.message === 'string' ? error.message : 'Request failed'),
            { status: res.status, body: error },
        ) as ApiError

        throw err
    }

    return res.json() as Promise<T>
}

export const fetchNodeTypes = (): Promise<NodeTypePayload[]> =>
    request<{ data: NodeTypePayload[] }>('GET', '/node-types').then((r) => r.data)

export const saveDraft = (
    flowId: string,
    definition: FlowDefinition,
    trigger: BuilderTriggerPayload | null,
    draftVersion: number | null,
): Promise<{ draft_version: number }> =>
    request('PUT', `/flows/${flowId}/draft`, { definition, trigger, draft_version: draftVersion })

export const validateFlow = (
    flowId: string,
    definition: FlowDefinition,
    trigger: BuilderTriggerPayload | null,
): Promise<ValidationResult> =>
    request('POST', `/flows/${flowId}/validate`, { definition, trigger })

export const publishFlow = (flowId: string): Promise<{ version: number }> =>
    request('POST', `/flows/${flowId}/publish`)
