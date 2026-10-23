// @vitest-environment happy-dom
import {beforeEach, describe, expect, it, vi} from 'vitest'
import {mount} from '@vue/test-utils'
import {createPinia, setActivePinia} from 'pinia'

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({props: {translations: {builder: {nodes: {branch: {otherwise: 'Otherwise', branch_n: 'Branch :n'}}}}}}),
}))

import FlowStructureNode from './FlowStructureNode.vue'
import {useBuilderStore} from '@builder/store/builderStore'
import {useSelectionStore} from '@builder/store/selectionStore'

const left = {ref: 'source', source: 'call', field: 'ok'}

function conditionTree() {
    const condition = {
        id: 'c1',
        type: 'condition',
        config: {
            rules: [
                {handle: 'u-1', label: '', left, operator: 'eq', value: 'yes'},
                {handle: 'u-2', label: 'Named', left: null},
            ],
        },
    }
    return {
        node: condition,
        handle: 'default',
        childrenByHandle: {
            'u-1': [{node: {id: 'a', type: 'end', config: {}}, handle: 'u-1', childrenByHandle: {}}],
            default: [{node: {id: 'b', type: 'end', config: {}}, handle: 'default', childrenByHandle: {}}],
        },
    }
}

describe('FlowStructureNode for a condition', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
        const builder = useBuilderStore()
        builder.definition = {nodes: [conditionTree().node as never], edges: []} as never
    })

    it('lists every rule branch and an Otherwise branch, never the handle', () => {
        const wrapper = mount(FlowStructureNode, {props: {treeNode: conditionTree() as never}})
        const branches = wrapper.findAll('.tree-branch').map((b) => b.text())
        expect(branches).toEqual(['call.ok = yes', 'Named', 'Otherwise'])
        expect(wrapper.text()).not.toContain('u-1')
    })

    it('nests blocks wired to default under Otherwise and focuses it on click', async () => {
        const wrapper = mount(FlowStructureNode, {props: {treeNode: conditionTree() as never}})
        const otherwise = wrapper.findAll('.tree-branch')[2]
        // the default child sits inside the Otherwise subtree, not flat after the node
        const otherwiseChildren = otherwise.element.nextElementSibling as HTMLElement
        expect(otherwiseChildren.querySelectorAll('.tree-item').length).toBe(1)

        await otherwise.trigger('click')
        expect(useSelectionStore().activeBranch).toEqual(['c1', 'default'])
    })
})
