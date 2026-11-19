// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import TriggerHint from './TriggerHint.vue'

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { translations: { console: { flows: { trigger_types: { message: 'Message' } } } } } }),
}))

const trigger = { type: 'message', text: 'hello, hi' }

describe('TriggerHint', () => {
  it('shows only the hint when the flow has no other live trigger', () => {
    const wrapper = mount(TriggerHint, { props: { trigger, moreTriggers: 0 } })

    expect(wrapper.text()).toBe('hello, hi')
  })

  it('appends the count of the other live triggers', () => {
    const wrapper = mount(TriggerHint, { props: { trigger, moreTriggers: 2 } })

    expect(wrapper.text()).toBe('hello, hi+2')
  })
})
