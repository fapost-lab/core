// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import TriggerHint from './TriggerHint.vue'

vi.mock('@inertiajs/vue3', () => ({
  usePage: () => ({ props: { translations: { console: { flows: { trigger_types: { message: 'Message' }, more_triggers: 'Other live triggers: :count' } } } } }),
}))

const trigger = { type: 'message', text: 'hello, hi' }

describe('TriggerHint', () => {
  it('shows only the hint when the flow has no other live trigger', () => {
    const wrapper = mount(TriggerHint, { props: { trigger, moreTriggers: 0 } })

    expect(wrapper.text()).toBe('hello, hi')
  })

  it('shows the count of the other live triggers as a badge of its own, named in full', () => {
    const wrapper = mount(TriggerHint, { props: { trigger: { type: 'message', text: 'a, b, c, d, e, f +3' }, moreTriggers: 2 } })
    const badge = wrapper.get('[data-testid="more-triggers"]')

    expect(badge.text()).toBe('+2')
    expect(badge.attributes('title')).toBe('Other live triggers: 2')
    expect(badge.attributes('aria-label')).toBe('Other live triggers: 2')
    expect(wrapper.find('.truncate').text()).toBe('a, b, c, d, e, f +3')
  })
})
