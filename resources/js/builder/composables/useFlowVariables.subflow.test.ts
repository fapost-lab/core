import {beforeEach, describe, expect, it, vi} from 'vitest'
import {createPinia, setActivePinia} from 'pinia'

vi.mock('@inertiajs/vue3', () => ({usePage: () => ({props: {translations: {builder: {}}}})}))
vi.mock('@builder/api/builderApi', () => ({fetchNodeTypes: vi.fn()}))

import {useBuilderStore} from '@builder/store/builderStore'
import {useFlowVariables} from './useFlowVariables'

describe('variable picker and a subflow node', () => {
    beforeEach(() => setActivePinia(createPinia()))

    it('offers each output_mapping target as a temporary variable', () => {
        const store = useBuilderStore()
        store.init({
            flowId: 'f1', name: 'F', draftVersion: 1,
            definition: {
                nodes: [
                    {id: 's', type: 'subflow', version: 1, config: {flow_id: 'child', output_mapping: {answer: 'result', score: 'points'}}},
                    {id: 'e', type: 'end', version: 1, config: {}},
                ],
                edges: [],
            },
        } as never)

        const paths = useFlowVariables().userVars.value.map((v) => v.path)

        expect(paths).toEqual(expect.arrayContaining(['flow.answer', 'flow.score']))
    })
})
