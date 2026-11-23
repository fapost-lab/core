// @vitest-environment happy-dom
import {beforeEach, describe, expect, it, vi} from 'vitest'
import {flushPromises, mount} from '@vue/test-utils'
import {createPinia, setActivePinia} from 'pinia'

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({props: {translations: {builder: {}}}}),
}))

const {fetchKnownTags, fetchSegmentOptions} = vi.hoisted(() => ({
    fetchKnownTags: vi.fn(),
    fetchSegmentOptions: vi.fn(),
}))

vi.mock('@builder/api/builderApi', async (importOriginal) => ({
    ...(await importOriginal<object>()),
    fetchKnownTags,
    fetchSegmentOptions,
}))

import SendMessageConfig from './SendMessageConfig.vue'
import {useBuilderStore} from '@builder/store/builderStore'

function mountConfig(config: Record<string, unknown>) {
    return mount(SendMessageConfig, {props: {node: {id: 'n1', type: 'send_message', version: 1, config}, schema: {}}})
}

function lastPatch(wrapper: ReturnType<typeof mountConfig>): Record<string, unknown> {
    const events = wrapper.emitted('update:config') as Array<[Record<string, unknown>]>
    return events[events.length - 1][0]
}

describe('SendMessageConfig addressee', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
        fetchKnownTags.mockResolvedValue(['vip', 'beta'])
        fetchSegmentOptions.mockResolvedValue([{value: 'seg-1', label: 'Buyers'}])
    })

    it('stores a contact expression as a contact addressee', async () => {
        const wrapper = mountConfig({content_type: 'text', text: 'Shipped'})

        await wrapper.find('[data-test=addressee-contact]').setValue('{{flow.customer_id}}')

        expect(lastPatch(wrapper)).toMatchObject({addressee: {kind: 'contact', contact: '{{flow.customer_id}}'}})
    })

    it('turns a keyboard message into plain text and clears what waits for an answer', async () => {
        const wrapper = mountConfig({
            content_type:     'text_with_keyboard',
            keyboard_mode:    'inline',
            text:             'Approve?',
            buttons:          [{id: 'b1', label: 'Yes', value: 'yes'}],
            timeout_seconds:  60,
            save_to_variable: {name: 'answer', storage: 'session'},
        })

        await wrapper.find('[data-test=addressee-contact]').setValue('{{flow.manager_id}}')

        const patch = lastPatch(wrapper)
        expect(patch).toMatchObject({content_type: 'text', timeout_seconds: null})
        expect('buttons' in patch).toBe(false)
        expect(patch.save_to_variable).toBeUndefined()
    })

    it('keeps the wired branches of the buttons through an addressee and back to a keyboard', async () => {
        const builder = useBuilderStore()
        builder.init({
            flowId: 'f1', name: 'F', draftVersion: 1, readOnly: false, triggers: [],
            definition: {
                nodes: [
                    {id: 'n1', type: 'send_message', version: 1, config: {content_type: 'text_with_keyboard', keyboard_mode: 'inline', text: 'Approve?', buttons: [{id: 'b1', label: 'Yes', value: 'yes'}]}},
                    {id: 'y', type: 'set_tag', version: 1, config: {}},
                ],
                edges: [{id: 'e1', from: 'n1', to: 'y', handle: 'b1'}],
            },
        } as never)
        const node = () => builder.definition.nodes[0]
        const wrapper = mount(SendMessageConfig, {props: {node: node(), schema: {}}})
        const apply = async () => {
            builder.updateNodeConfig('n1', lastPatch(wrapper) as never)
            await wrapper.setProps({node: {...node()}})
        }

        await wrapper.find('[data-test=addressee-contact]').setValue('{{flow.manager_id}}')
        await apply()
        expect(builder.definition.edges.map((edge) => edge.handle)).toContain('b1')

        await wrapper.find('[data-test=addressee-contact]').setValue('')
        await apply()
        await wrapper.find('select').setValue('text_with_keyboard')
        await apply()

        expect(node().config).toMatchObject({content_type: 'text_with_keyboard', buttons: [{id: 'b1', label: 'Yes', value: 'yes'}]})
        expect(builder.definition.edges.map((edge) => edge.handle)).toContain('b1')
    })

    it('removes the addressee when the expression is cleared and offers no keyboard while one is set', async () => {
        const wrapper = mountConfig({content_type: 'text', text: 'Hi', addressee: {kind: 'contact', contact: '{{flow.id}}'}})

        expect(wrapper.find('option[value=text_with_keyboard]').attributes('disabled')).toBeDefined()

        await wrapper.find('[data-test=addressee-contact]').setValue('  ')

        expect(lastPatch(wrapper)).toEqual({addressee: null})
    })
})

describe('SendMessageConfig audience addressee', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
        fetchKnownTags.mockResolvedValue(['vip', 'beta'])
        fetchSegmentOptions.mockResolvedValue([{value: 'seg-1', label: 'Buyers'}])
    })

    it('switches to tags with a text-only message and no waiting controls', async () => {
        const wrapper = mountConfig({
            content_type: 'image', media_file_id: 'f1', timeout_seconds: 30, addressee: {kind: 'contact', contact: '{{flow.id}}'},
        })

        await wrapper.find('[data-test=addressee-kind-tags]').trigger('click')

        const patch = lastPatch(wrapper)
        expect(patch).toMatchObject({addressee: {kind: 'tags', tags: []}, content_type: 'text', timeout_seconds: null})
        expect('buttons' in patch).toBe(false)
        expect(patch.media_file_id).toBeUndefined()
    })

    it('switches to a segment', async () => {
        const wrapper = mountConfig({content_type: 'text', text: 'Hi'})

        await wrapper.find('[data-test=addressee-kind-segment]').trigger('click')

        expect(lastPatch(wrapper)).toMatchObject({addressee: {kind: 'segment', segment_id: ''}, content_type: 'text'})
    })

    it('drops the audience keys when switching back to a contact', async () => {
        const wrapper = mountConfig({
            content_type: 'text', addressee: {kind: 'tags', tags: ['vip']}, broadcast_name: 'Promo', save_broadcast_to: 'flow.broadcast',
        })

        await wrapper.find('[data-test=addressee-kind-contact]').trigger('click')

        const patch = lastPatch(wrapper)
        expect(patch.addressee).toBeNull()
        expect(patch.broadcast_name).toBeUndefined()
        expect(patch.save_broadcast_to).toBeUndefined()
    })

    it('offers only text while an audience is set', () => {
        const wrapper = mountConfig({content_type: 'text', addressee: {kind: 'segment', segment_id: 'seg-1'}})

        for (const value of ['text_with_keyboard', 'image', 'document', 'video', 'voice']) {
            expect(wrapper.find(`option[value=${value}]`).attributes('disabled')).toBeDefined()
        }
        expect(wrapper.find('option[value=text]').attributes('disabled')).toBeUndefined()
    })

    it('loads tag and segment options from the endpoints', async () => {
        const wrapper = mountConfig({content_type: 'text', addressee: {kind: 'tags', tags: ['vip']}})
        await flushPromises()

        expect(fetchKnownTags).toHaveBeenCalled()
        expect(fetchSegmentOptions).toHaveBeenCalled()
        expect(wrapper.vm).toBeTruthy()
    })

    it('writes the broadcast name and the variable to save it to', async () => {
        const wrapper = mountConfig({content_type: 'text', addressee: {kind: 'tags', tags: ['vip']}})

        await wrapper.find('[data-test=broadcast-name]').setValue('Promo')
        expect(lastPatch(wrapper)).toEqual({broadcast_name: 'Promo'})

        await wrapper.find('[data-test=save-broadcast-to]').setValue('flow.broadcast')
        expect(lastPatch(wrapper)).toEqual({save_broadcast_to: 'flow.broadcast'})
    })
})
