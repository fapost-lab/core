import {beforeEach, describe, expect, it, vi} from 'vitest'
import {createPinia, setActivePinia} from 'pinia'

const fetchNodeTypes = vi.fn()
vi.mock('@builder/api/builderApi', () => ({fetchNodeTypes: () => fetchNodeTypes()}))

import {useRegistryStore} from '@builder/store/registryStore'
import {useFlowVariables} from './useFlowVariables'
import {useConditionSources} from './useConditionSources'

describe('Solution state namespaces in the pickers', () => {
    beforeEach(async () => {
        setActivePinia(createPinia())
        fetchNodeTypes.mockResolvedValue({data: [], state_namespaces: [{key: 'feedback', owner: 'feedback'}]})
        await useRegistryStore().load()
    })

    it('offers each namespace as a free-path entry in the variable picker', () => {
        const {allVars} = useFlowVariables()
        const entry = allVars.value.find((v) => v.source.kind === 'solution')

        expect(entry?.path).toBe('feedback.')
        expect(entry?.source).toEqual({kind: 'solution', namespace: 'feedback'})
    })

    it('offers each namespace as a condition source without a field list', () => {
        const {sources, findSource} = useConditionSources()

        expect(sources.value.map((s) => s.id)).toContain('feedback')
        expect(findSource('feedback')?.fields).toEqual([])
    })

    it('offers nothing when no Solution namespace is active', async () => {
        setActivePinia(createPinia())
        fetchNodeTypes.mockResolvedValue({data: []})
        await useRegistryStore().load()

        expect(useConditionSources().sources.value.map((s) => s.id)).not.toContain('feedback')
    })
})
