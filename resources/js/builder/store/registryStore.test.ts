import {beforeEach, describe, expect, it, vi} from 'vitest'
import {createPinia, setActivePinia} from 'pinia'

const fetchNodeTypes = vi.fn()
vi.mock('@builder/api/builderApi', () => ({fetchNodeTypes: () => fetchNodeTypes()}))

import {useRegistryStore} from './registryStore'

describe('registry store', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
        fetchNodeTypes.mockReset()
    })

    it('keeps node types and the ordered groups', async () => {
        const groups = [{key: 'Logic', label: 'Logic', owner: null}]
        fetchNodeTypes.mockResolvedValue({data: [{type: 'delay', version: 1}], groups})
        const store = useRegistryStore()
        await store.load()

        expect(store.getByType('delay', 1)).toBeDefined()
        expect(store.groups).toEqual(groups)
    })

    it('tolerates a response without groups', async () => {
        fetchNodeTypes.mockResolvedValue({data: [{type: 'delay', version: 1}]})
        const store = useRegistryStore()
        await store.load()

        expect(store.groups).toEqual([])
    })

    it('keeps the state namespaces of active Solutions, empty when the response has none', async () => {
        fetchNodeTypes.mockResolvedValue({data: [], state_namespaces: [{key: 'feedback', owner: 'feedback'}]})
        const store = useRegistryStore()
        await store.load()
        expect(store.stateNamespaces).toEqual([{key: 'feedback', owner: 'feedback'}])

        setActivePinia(createPinia())
        fetchNodeTypes.mockResolvedValue({data: []})
        const other = useRegistryStore()
        await other.load()
        expect(other.stateNamespaces).toEqual([])
    })
})
