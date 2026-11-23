// @vitest-environment happy-dom
import {beforeEach, describe, expect, it, vi} from 'vitest'
import {mount} from '@vue/test-utils'
import {createPinia, setActivePinia} from 'pinia'

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({props: {translations: {builder: {}}}}),
}))

import AskPersonConfig from './AskPersonConfig.vue'

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

function mountConfig(config: Record<string, unknown>) {
    return mount(AskPersonConfig, {props: {node: {id: 'n1', type: 'ask_person', version: 1, config}, schema: {}}})
}

function lastPatch(wrapper: ReturnType<typeof mountConfig>): Record<string, unknown> {
    const events = wrapper.emitted('update:config') as Array<[Record<string, unknown>]>
    return events[events.length - 1][0]
}

function buttons(count: number): Array<Record<string, string>> {
    return Array.from({length: count}, (_, i) => ({id: `b-${i}`, label: `L${i}`, value: `v${i}`}))
}

describe('AskPersonConfig', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
    })

    it('gives a new node two buttons with uuid ids', () => {
        const wrapper = mountConfig({})

        const patch = lastPatch(wrapper).buttons as Array<{id: string; value: string}>
        expect(patch).toHaveLength(2)
        expect(patch.every((btn) => UUID.test(btn.id))).toBe(true)
        expect(new Set(patch.map((btn) => btn.id)).size).toBe(2)
    })

    it('leaves existing buttons alone on mount', () => {
        const wrapper = mountConfig({buttons: buttons(2)})

        expect(wrapper.emitted('update:config')).toBeUndefined()
    })

    it('adds a button with a fresh uuid id', async () => {
        const wrapper = mountConfig({buttons: buttons(2)})

        await wrapper.find('[data-test=add-button]').trigger('click')

        const patch = lastPatch(wrapper).buttons as Array<{id: string}>
        expect(patch).toHaveLength(3)
        expect(patch[2].id).toMatch(UUID)
    })

    it('does not offer more than eight buttons', async () => {
        const wrapper = mountConfig({buttons: buttons(8)})

        expect(wrapper.find('[data-test=add-button]').attributes('disabled')).toBeDefined()
        await wrapper.find('[data-test=add-button]').trigger('click')

        expect(wrapper.emitted('update:config')).toBeUndefined()
    })

    it('keeps the id of a button when its label is renamed', async () => {
        const wrapper = mountConfig({buttons: buttons(2)})

        await wrapper.findAll('[data-test=button-label]')[1].setValue('Renamed')

        const patch = lastPatch(wrapper).buttons as Array<{id: string; label: string}>
        expect(patch[1]).toMatchObject({id: 'b-1', label: 'Renamed'})
    })

    it('removes and reorders buttons', async () => {
        const wrapper = mountConfig({buttons: buttons(3)})

        await wrapper.findAll('[data-test=button-remove]')[0].trigger('click')
        expect((lastPatch(wrapper).buttons as Array<{id: string}>).map((b) => b.id)).toEqual(['b-1', 'b-2'])

        await wrapper.findAll('[data-test=button-down]')[0].trigger('click')
        expect((lastPatch(wrapper).buttons as Array<{id: string}>).map((b) => b.id)).toEqual(['b-1', 'b-0', 'b-2'])
    })

    it('stores the addressee as a contact expression and clamps the timeout', async () => {
        const wrapper = mountConfig({buttons: buttons(2)})

        await wrapper.find('[data-test=addressee-contact]').setValue('{{flow.manager_id}}')
        expect(lastPatch(wrapper)).toEqual({addressee: {kind: 'contact', contact: '{{flow.manager_id}}'}})

        await wrapper.find('[data-test=timeout-seconds]').setValue('5')
        expect(lastPatch(wrapper)).toEqual({timeout_seconds: 60})

        await wrapper.find('[data-test=timeout-seconds]').setValue('9999999')
        expect(lastPatch(wrapper)).toEqual({timeout_seconds: 604800})
    })

    it('shows a translated label read-only', () => {
        const wrapper = mountConfig({buttons: [{id: 'b-0', label: {en: 'Yes', ru: 'Да'}, value: 'yes'}]})

        expect(wrapper.find('[data-test=button-label]').attributes('disabled')).toBeDefined()
        expect((wrapper.find('[data-test=button-label]').element as HTMLInputElement).value).toBe('Yes')
    })
})
