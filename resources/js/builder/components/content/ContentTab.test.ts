// @vitest-environment happy-dom
import {beforeEach, describe, expect, it, vi} from 'vitest'
import {mount} from '@vue/test-utils'
import {createPinia, setActivePinia} from 'pinia'

vi.mock('@inertiajs/vue3', () => ({usePage: () => ({props: {translations: {builder: {content: {
    send_message_text: 'Message text', send_message_caption: 'Caption', input_prompt: 'Prompt', input_on_invalid: 'On invalid',
}}}}})}))

import ContentTab from './ContentTab.vue'
import ContentEditorPanel from './ContentEditorPanel.vue'
import {useBuilderStore} from '@builder/store/builderStore'

type Node = Record<string, unknown>

const reference = (config: Record<string, unknown>): Node => ({
    id: 'tpl',
    type: 'template',
    version: 1,
    config: {
        template_flow_id: 'tpl-1',
        outputs: [],
        parameters: [
            {key: 'greeting', label: 'Greeting', node_type: 'send_message', node_version: 1, field: 'text'},
            {key: 'delay', label: 'Delay', node_type: 'wait', node_version: 1, field: 'delay'},
            {key: 'ask', label: 'Ask', node_type: 'input', node_version: 1, field: 'prompt'},
        ],
        ...config,
    },
})

function setup(nodes: Node[]) {
    const builder = useBuilderStore()
    builder.definition = {nodes, edges: []} as never
    builder.availableLanguages = ['en', 'uk']
    builder.contentBaseLanguage = 'en'

    return {builder, wrapper: mount(ContentTab)}
}

const keys = (wrapper: ReturnType<typeof setup>['wrapper']) => wrapper.findAll('.field-item .field-name').map((el) => el.text())

async function edit(wrapper: ReturnType<typeof setup>['wrapper'], index: number, translations: Record<string, string>) {
    await wrapper.findAll('.field-item')[index].trigger('click')
    const panel = wrapper.findComponent(ContentEditorPanel)
    for (const [lang, text] of Object.entries(translations)) {
        panel.vm.$emit('update', 'k', lang, text)
    }
    panel.vm.$emit('save')
}

describe('ContentTab entries', () => {
    beforeEach(() => setActivePinia(createPinia()))

    it('lists a text message by its content type: text and buttons for text, a caption for media', () => {
        const {wrapper} = setup([
            {id: 'm1', type: 'send_message', config: {content_type: 'text_with_keyboard', text: {en: 'Hi'}, buttons: [{label: {en: 'Yes'}}]}},
            {id: 'm2', type: 'send_message', config: {content_type: 'image', text: {en: 'ignored'}, caption: {en: 'Look'}}},
        ])

        expect(keys(wrapper)).toEqual(['Message text', 'Yes', 'Caption'])
        expect(wrapper.findAll('.field-kind').map((el) => el.text())).toEqual(['Btn', 'Cap'])
    })

    it('lists the prompt and the invalid message of an input only when they are filled', () => {
        const {wrapper} = setup([
            {id: 'i1', type: 'input', config: {prompt: {en: 'Name?'}, on_invalid_message: ''}},
            {id: 'i2', type: 'input', config: {on_invalid_message: {en: 'No'}}},
            {id: 'i3', type: 'input', config: {}},
        ])

        expect(keys(wrapper)).toEqual(['Prompt', 'On invalid'])
        expect(wrapper.findAll('.field-kind').map((el) => el.text())).toEqual(['Ask', 'Err'])
    })

    it('lists a template parameter only when it is translatable and the host gave it a value', () => {
        const {wrapper} = setup([reference({parameter_values: {greeting: {en: 'Hello'}, delay: 'PT1H', ask: {en: '  '}}})])

        expect(keys(wrapper)).toEqual(['Greeting'])
    })

    it('lists nothing for a template reference without values', () => {
        const {wrapper} = setup([reference({})])

        expect(wrapper.text()).toContain('No translatable fields')
    })
})

describe('ContentTab saving', () => {
    beforeEach(() => setActivePinia(createPinia()))

    it('writes a top-level text as a language map on the node config', async () => {
        const {wrapper, builder} = setup([
            {id: 'm1', type: 'send_message', config: {content_type: 'text', text: 'Hi', keep: 1}},
            {id: 'i1', type: 'input', config: {prompt: {en: 'Name?'}, on_invalid_message: {en: 'No'}}},
        ])

        await edit(wrapper, 0, {en: 'Hi', uk: 'Привіт'})
        expect(builder.definition.nodes[0].config).toEqual({content_type: 'text', text: {en: 'Hi', uk: 'Привіт'}, keep: 1})

        await edit(wrapper, 2, {en: 'Wrong', uk: 'Ні'})
        expect(builder.definition.nodes[1].config).toEqual({prompt: {en: 'Name?'}, on_invalid_message: {en: 'Wrong', uk: 'Ні'}})
    })

    it('writes a button label without touching the other buttons', async () => {
        const {wrapper, builder} = setup([
            {id: 'm1', type: 'send_message', config: {content_type: 'text_with_keyboard', text: 'x', buttons: [{id: 'b1', label: {en: 'Yes'}}, {id: 'b2', label: {en: 'No'}}]}},
        ])

        await edit(wrapper, 2, {en: 'No', uk: 'Ні'})

        expect((builder.definition.nodes[0].config as {buttons: unknown[]}).buttons).toEqual([
            {id: 'b1', label: {en: 'Yes'}},
            {id: 'b2', label: {en: 'No', uk: 'Ні'}},
        ])
    })

    it('writes a template parameter into parameter_values by its key and leaves the other keys', async () => {
        const {wrapper, builder} = setup([reference({parameter_values: {greeting: {en: 'Hello'}, delay: 'PT1H'}})])

        await edit(wrapper, 0, {en: 'Hello', uk: 'Вітаю'})

        expect((builder.definition.nodes[0].config as Record<string, unknown>).parameter_values).toEqual({
            greeting: {en: 'Hello', uk: 'Вітаю'},
            delay: 'PT1H',
        })
    })
})
