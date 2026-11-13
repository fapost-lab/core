// @vitest-environment happy-dom
import {describe, expect, it} from 'vitest'
import {mount} from '@vue/test-utils'
import SchemaConfigRenderer from './SchemaConfigRenderer.vue'

describe('SchemaConfigRenderer with an empty schema', () => {
    it('renders without emitting any config change (stored config is never rewritten)', () => {
        const node = {id: 'x1', type: 'gone.node', version: 1, config: {kept: {a: 1}}}
        const wrapper = mount(SchemaConfigRenderer, {props: {node, schema: {}}})

        expect(wrapper.emitted('update:config')).toBeUndefined()
        expect(node.config).toEqual({kept: {a: 1}})
    })
})
