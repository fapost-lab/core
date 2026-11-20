// @vitest-environment happy-dom
import {beforeEach, describe, expect, it, vi} from 'vitest'
import {createPinia, setActivePinia} from 'pinia'

const {publishFlow, saveDraft} = vi.hoisted(() => ({publishFlow: vi.fn(), saveDraft: vi.fn()}))

vi.mock('@builder/api/builderApi', async (importOriginal) => ({
    ...(await importOriginal<object>()),
    publishFlow,
    saveDraft,
}))
vi.mock('@builder/composables/useValidation', () => ({
    useValidation: () => ({validate: async () => ({valid: true})}),
}))

import {useBuilderStore} from '@builder/store/builderStore'
import {usePublish} from './usePublish'

function apiError(status: number, message: string): Error {
    return Object.assign(new Error(message), {status, body: {message}})
}

describe('usePublish', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
        saveDraft.mockReset()
        publishFlow.mockReset()
        saveDraft.mockResolvedValue({draft_version: 2})
    })

    it('shows the reason of a 403 refusal instead of throwing', async () => {
        publishFlow.mockRejectedValue(apiError(403, 'You may not change automatic triggers.'))
        const store = useBuilderStore()
        store.flowId = 'f1'

        const result = await usePublish().publish()

        expect(result).toEqual({success: false})
        expect(store.validationResult?.valid).toBe(false)
        expect(store.validationResult?.errors?.[0].message).toBe('You may not change automatic triggers.')
    })

    it('still throws other failures', async () => {
        publishFlow.mockRejectedValue(apiError(500, 'Boom'))
        const store = useBuilderStore()
        store.flowId = 'f1'

        await expect(usePublish().publish()).rejects.toThrow('Boom')
    })
})
