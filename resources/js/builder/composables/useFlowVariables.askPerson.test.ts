import {beforeEach, describe, expect, it, vi} from 'vitest'
import {createPinia, setActivePinia} from 'pinia'

vi.mock('@inertiajs/vue3', () => ({usePage: () => ({props: {translations: {builder: {}}}})}))
vi.mock('@builder/api/builderApi', () => ({fetchNodeTypes: vi.fn()}))

import {useBuilderStore} from '@builder/store/builderStore'
import {useFlowVariables} from './useFlowVariables'

describe('variable picker and an ask_person node', () => {
    beforeEach(() => setActivePinia(createPinia()))

    it('offers the variable the answer is saved to', () => {
        const store = useBuilderStore()
        store.init({
            flowId: 'f1', name: 'F', draftVersion: 1,
            definition: {
                nodes: [
                    {id: 'q', type: 'ask_person', version: 1, config: {save_to_variable: {name: 'decision', type: 'text', storage: 'session'}}},
                    {id: 'e', type: 'end', version: 1, config: {}},
                ],
                edges: [],
            },
        } as never)

        const paths = useFlowVariables().userVars.value.map((v) => v.path)

        expect(paths).toContain('flow.decision')
    })
})
