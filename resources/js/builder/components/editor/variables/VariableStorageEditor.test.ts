// @vitest-environment happy-dom
import {beforeEach, describe, expect, it, vi} from 'vitest'
import {mount} from '@vue/test-utils'
import {createPinia, setActivePinia} from 'pinia'

vi.mock('@inertiajs/vue3', () => ({usePage: () => ({props: {translations: {builder: {}}}})}))
vi.mock('@builder/api/builderApi', () => ({fetchNodeTypes: vi.fn()}))

import VariableStorageEditor from './VariableStorageEditor.vue'

describe('VariableStorageEditor list size', () => {
    beforeEach(() => setActivePinia(createPinia()))

    it('keeps an out-of-range max size and shows the error', async () => {
        const wrapper = mount(VariableStorageEditor, {
            props: {
                modelValue: {name: 'photos', type: 'photo', storage: 'contact', group: null, isList: true, maxSize: null},
                showList: true,
            },
        })

        const input = wrapper.find('input[type="number"]')
        await input.setValue('1500')

        const emitted = wrapper.emitted('update:modelValue') as Array<[{maxSize: number | null}]>
        expect(emitted.at(-1)?.[0].maxSize).toBe(1500)
        expect(wrapper.text()).toContain('Whole number from 1 to 1000')
    })
})
