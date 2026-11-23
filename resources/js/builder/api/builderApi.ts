import type {BuilderTrigger, FlowDefinition, NodeGroup, NodeTypePayload, ReadOnlyNotice, StateNamespaceEntry, ValidationResult, WebhookEndpointInfo,} from '@builder/dto/types'
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

/**
 * The 423 answer the server gives to a write while the tenant is stopped: `null` for any other error,
 * otherwise the operator's notice (itself null when the operator wrote none).
 */
export function tenantStoppedNotice(error: unknown): { notice: ReadOnlyNotice | null } | null {
    const err = error as Partial<ApiError> & { body?: { error?: unknown; notice?: ReadOnlyNotice | null } }

    if (err?.status !== 423 || err.body?.error !== 'tenant_stopped') {
        return null
    }

    return { notice: err.body.notice ?? null }
}

export interface NodeTypesResponse {
    data: NodeTypePayload[]
    groups?: NodeGroup[]
    state_namespaces?: StateNamespaceEntry[]
}

export const fetchNodeTypes = (): Promise<NodeTypesResponse> =>
    request<NodeTypesResponse>('GET', '/node-types')

/** Distinct contact tags already used in this tenant — powers set_tag autocomplete. */
export const fetchKnownTags = (): Promise<string[]> =>
    request<{ data: string[] }>('GET', '/tags').then((r) => r.data)

export interface SelectOption {
    value: string
    label: string
}

/** Active staff users for the notify node (staff mode) recipient picker. */
export const fetchStaffOptions = (): Promise<SelectOption[]> =>
    request<{ data: SelectOption[] }>('GET', '/staff').then((r) => r.data)

/** A saved credential as the call node's picker lists it: never a setting, host or secret. */
export interface CredentialOption {
    id: string
    name: string
    type: string
}

/** The tenant's saved credentials for the call node's authorization picker. */
export const fetchCredentialOptions = (): Promise<CredentialOption[]> =>
    request<{ data: CredentialOption[] }>('GET', '/credentials').then((r) => r.data)

/** Tenant assistants for the notify node (contacts mode) target picker. */
export const fetchAssistantOptions = (): Promise<SelectOption[]> =>
    request<{ data: SelectOption[] }>('GET', '/assistants').then((r) => r.data)

/** Tenant contact segments for the send_message node (segment addressee) picker. */
export const fetchSegmentOptions = (): Promise<SelectOption[]> =>
    request<{ data: SelectOption[] }>('GET', '/segments').then((r) => r.data)

export const saveDraft = (
    flowId: string,
    definition: FlowDefinition,
    triggers: BuilderTrigger[],
    draftVersion: number | null,
): Promise<{ draft_version: number }> =>
    request('PUT', `/flows/${flowId}/draft`, { definition, triggers, draft_version: draftVersion })

export const validateFlow = (
    flowId: string,
    definition: FlowDefinition,
    triggers: BuilderTrigger[],
): Promise<ValidationResult> =>
    request('POST', `/flows/${flowId}/validate`, { definition, triggers })

export const publishFlow = (flowId: string): Promise<{ version: number }> =>
    request('POST', `/flows/${flowId}/publish`)

export interface CallTestResult {
    success:     boolean
    status_code: number | null
    headers:     Record<string, unknown>
    body:        unknown
    error_code:  string | null
    duration_ms: number
}

/** Execute a call-node config live and return the response for shape inspection. */
export const testCall = (
    config: Record<string, unknown>,
    sample: Record<string, string>,
): Promise<CallTestResult> =>
    request<{ data: CallTestResult }>('POST', '/call/test', { config, sample }).then((r) => r.data)

export type WebhookSecretResult = WebhookEndpointInfo & { secret: string | null }

/** Creates the flow's webhook URL for a trigger when it has none. The trigger must be in the saved draft. */
export const createWebhookEndpoint = (flowId: string, triggerId: string): Promise<WebhookEndpointInfo> =>
    request<{ data: WebhookEndpointInfo }>('POST', `/flows/${flowId}/triggers/${triggerId}/webhook`, {}).then((r) => r.data)

/** Generates a secret (shown once, in `secret`), or stores the sender's own when `secret` is given. */
export const setWebhookSecret = (flowId: string, triggerId: string, secret: string | null = null): Promise<WebhookSecretResult> =>
    request<{ data: WebhookSecretResult }>(
        'POST',
        `/flows/${flowId}/triggers/${triggerId}/webhook/secret`,
        secret === null ? {} : { secret },
    ).then((r) => r.data)

export const generateWebhookSecret = (flowId: string, triggerId: string): Promise<WebhookSecretResult> =>
    setWebhookSecret(flowId, triggerId)
