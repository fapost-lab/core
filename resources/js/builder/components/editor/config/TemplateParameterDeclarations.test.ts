// @vitest-environment happy-dom
import {beforeEach, describe, expect, it, vi} from 'vitest'
import {mount} from '@vue/test-utils'
import {createPinia, setActivePinia} from 'pinia'

vi.mock('@inertiajs/vue3', () => ({usePage: () => ({props: {translations: {builder: {nodes: {template: {
    parameter_key_invalid: 'Invalid key',
    parameter_key_taken: 'Key taken',
    remove_parameter: 'Remove',
    add_parameter: 'Add',
}}}}}})}))

import TemplateParameterDeclarations from './TemplateParameterDeclarations.vue'
import {useBuilderStore} from '@builder/store/builderStore'

const schema = {
    text: {type: 'text', label: 'Message text'},
    delay: {type: 'duration', label: 'Delay'},
    rows: {type: 'object-array', label: 'Rows'},
}

function setup(nodes: Array<Record<string, unknown>>) {
    const builder = useBuilderStore()
    builder.definition = {nodes, edges: []} as never

    return {
        builder,
        wrapper: mount(TemplateParameterDeclarations, {props: {nodeId: 'a', nodeType: 'send_message', schema}}),
    }
}

const nodeA = (parameters?: Array<Record<string, unknown>>) => ({id: 'a', type: 'send_message', version: 1, config: {}, ...(parameters ? {parameters} : {})})

async function fill(wrapper: ReturnType<typeof setup>['wrapper'], field: string, key?: string) {
    await wrapper.find('select').setValue(field)
    if (key !== undefined) {
        await wrapper.find('input.param-key').setValue(key)
    }
}

describe('TemplateParameterDeclarations', () => {
    beforeEach(() => setActivePinia(createPinia()))

    it('offers only the top-level fields a template may expose', () => {
        const {wrapper} = setup([nodeA()])

        const options = wrapper.findAll('select option').map((o) => o.text())
        expect(options).toContain('Message text')
        expect(options).toContain('Delay')
        expect(options).not.toContain('Rows')
    })

    it('adds a declaration with the field as the default key and label', async () => {
        const {wrapper, builder} = setup([nodeA()])

        await fill(wrapper, 'text')
        expect((wrapper.find('input.param-key').element as HTMLInputElement).value).toBe('text')

        await wrapper.find('button.param-add').trigger('click')

        expect(builder.definition.nodes[0].parameters).toEqual([{key: 'text', label: 'Message text', field: 'text'}])
    })

    it('refuses a key that does not match the pattern', async () => {
        const {wrapper, builder} = setup([nodeA()])

        await fill(wrapper, 'text', 'Bad Key')

        expect(wrapper.find('.param-warn').text()).toBe('Invalid key')
        expect(wrapper.find('button.param-add').attributes('disabled')).toBeDefined()
        await wrapper.find('button.param-add').trigger('click')
        expect(builder.definition.nodes[0].parameters).toBeUndefined()
    })

    it('refuses a key already declared by another node of the template, and by this one', async () => {
        const {wrapper} = setup([
            nodeA([{key: 'delay', label: null, field: 'delay'}]),
            {id: 'b', type: 'send_message', version: 1, config: {}, parameters: [{key: 'greeting', label: null, field: 'text'}]},
        ])

        await fill(wrapper, 'text', 'greeting')
        expect(wrapper.find('.param-warn').text()).toBe('Key taken')
        expect(wrapper.find('button.param-add').attributes('disabled')).toBeDefined()

        await wrapper.find('input.param-key').setValue('delay')
        expect(wrapper.find('.param-warn').text()).toBe('Key taken')

        await wrapper.find('input.param-key').setValue('fresh_key')
        expect(wrapper.find('.param-warn').exists()).toBe(false)
    })

    it('no longer offers a declared field, and removing the declaration offers it again', async () => {
        const {wrapper, builder} = setup([nodeA([{key: 'text', label: null, field: 'text'}])])

        expect(wrapper.findAll('select option').map((o) => o.text())).not.toContain('Message text')

        await wrapper.find('button.param-remove').trigger('click')

        expect(builder.definition.nodes[0].parameters).toBeUndefined()
        expect(wrapper.findAll('select option').map((o) => o.text())).toContain('Message text')
    })

    it('keeps the other declarations when one is removed', async () => {
        const {wrapper, builder} = setup([nodeA([
            {key: 'text', label: null, field: 'text'},
            {key: 'delay', label: 'Wait', field: 'delay'},
        ])])

        await wrapper.findAll('button.param-remove')[0].trigger('click')

        expect(builder.definition.nodes[0].parameters).toEqual([{key: 'delay', label: 'Wait', field: 'delay'}])
    })
})
