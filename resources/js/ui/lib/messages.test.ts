// @vitest-environment happy-dom
import {mount} from '@vue/test-utils'
import {defineComponent, h, ref} from 'vue'
import {describe, expect, it} from 'vitest'
import {DEFAULT_KIT_MESSAGES, KIT_MESSAGES, resolveKitMessages, useKitMessages} from './messages'

const Probe = defineComponent({
    setup() {
        const messages = useKitMessages()

        return () => h('span', `${messages.value.close}|${messages.value.table.search}|${messages.value.table.next}`)
    },
})

describe('resolveKitMessages', () => {
    it('is English without a host', () => {
        expect(resolveKitMessages(null)).toEqual(DEFAULT_KIT_MESSAGES)
    })

    it('takes what the host gives and keeps the default for the rest', () => {
        const messages = resolveKitMessages({close: 'Закрыть', table: {search: 'Поиск', next: ''}})

        expect(messages.close).toBe('Закрыть')
        expect(messages.table.search).toBe('Поиск')
        expect(messages.table.next).toBe(DEFAULT_KIT_MESSAGES.table.next)
        expect(messages.table.previous).toBe(DEFAULT_KIT_MESSAGES.table.previous)
    })

    it('ignores keys the kit does not know', () => {
        const messages = resolveKitMessages({table: {unknown: 'x'} as never})

        expect(messages.table).toEqual(DEFAULT_KIT_MESSAGES.table)
    })
})

describe('useKitMessages', () => {
    it('falls back to English when nothing is provided', () => {
        expect(mount(Probe).text()).toBe('Close|Search|Next page')
    })

    it('follows what the host provides, as it changes', async () => {
        const locale = ref<'ru' | 'uk'>('ru')
        const wrapper = mount(Probe, {
            global: {
                provide: {
                    [KIT_MESSAGES as symbol]: () => (locale.value === 'ru' ? {close: 'Закрыть', table: {search: 'Поиск'}} : {close: 'Закрити', table: {search: 'Пошук'}}),
                },
            },
        })

        expect(wrapper.text()).toBe('Закрыть|Поиск|Next page')

        locale.value = 'uk'
        await wrapper.vm.$nextTick()

        expect(wrapper.text()).toBe('Закрити|Пошук|Next page')
    })
})
