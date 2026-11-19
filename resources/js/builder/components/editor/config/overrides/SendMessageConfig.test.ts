// @vitest-environment happy-dom
import {beforeEach, describe, expect, it, vi} from 'vitest'
import {mount} from '@vue/test-utils'
import {createPinia, setActivePinia} from 'pinia'

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({props: {translations: {builder: {}}}}),
}))

import SendMessageConfig from './SendMessageConfig.vue'

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
        expect(patch).toMatchObject({content_type: 'text', buttons: [], timeout_seconds: null})
        expect(patch.save_to_variable).toBeUndefined()
    })

    it('removes the addressee when the expression is cleared and offers no keyboard while one is set', async () => {
        const wrapper = mountConfig({content_type: 'text', text: 'Hi', addressee: {kind: 'contact', contact: '{{flow.id}}'}})

        expect(wrapper.find('option[value=text_with_keyboard]').attributes('disabled')).toBeDefined()

        await wrapper.find('[data-test=addressee-contact]').setValue('  ')

        expect(lastPatch(wrapper)).toEqual({addressee: null})
    })
})
