// @vitest-environment happy-dom
import {beforeEach, describe, expect, it, vi} from 'vitest'
import {mount} from '@vue/test-utils'
import {createPinia, setActivePinia} from 'pinia'

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({props: {translations: {builder: {}}}}),
}))

vi.mock('@builder/api/builderApi', () => ({
    fetchStaffOptions:     () => Promise.resolve([]),
    fetchAssistantOptions: () => Promise.resolve([]),
    fetchKnownTags:        () => Promise.resolve([]),
}))

import NotifyConfig from './NotifyConfig.vue'

// The modes as NotifyNodeHandler::configSchema() sends them: both, so a published contacts node still renders.
const schema = {
    mode: {type: 'select', options: {staff: 'Staff', contacts: 'Contacts (retired)'}},
}

function mountConfig(config: Record<string, unknown>) {
    return mount(NotifyConfig, {
        props: {node: {id: 'n1', version: 1, config}, schema},
        global: {stubs: {SearchSelect: true, TextareaField: true}},
    })
}

function modeValues(wrapper: ReturnType<typeof mountConfig>): string[] {
    return wrapper.findAll('option').map((option) => option.attributes('value') ?? '').filter((v) => v === 'staff' || v === 'contacts')
}

describe('NotifyConfig contacts mode', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
    })

    it('does not offer the retired contacts mode to a staff node', () => {
        const wrapper = mountConfig({mode: 'staff'})

        expect(modeValues(wrapper)).toEqual(['staff'])
        expect(wrapper.find('[data-testid="notify-contacts-retired"]').exists()).toBe(false)
    })

    it('keeps the contacts mode on a node opened in it and says it is retired', () => {
        const wrapper = mountConfig({mode: 'contacts', assistant_id: 'a1', contact_target: 'tag', tags: ['vip'], message: 'Hi'})

        expect(modeValues(wrapper)).toEqual(['staff', 'contacts'])
        expect(wrapper.find('[data-testid="notify-contacts-retired"]').exists()).toBe(true)
    })
})
