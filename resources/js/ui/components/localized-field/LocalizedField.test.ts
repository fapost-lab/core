// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import LocalizedField from './LocalizedField.vue'
import type { LocalizedEntry } from './localized'

const languages = [{ code: 'ru', required: true }, { code: 'en' }]

function mountField(entries: LocalizedEntry[], props: Record<string, unknown> = {}) {
  const wrapper = mount(LocalizedField, {
    attachTo: document.body,
    props: {
      id: 'message',
      languages,
      modelValue: entries,
      'onUpdate:modelValue': (next: LocalizedEntry[]) => wrapper.setProps({ modelValue: next }),
      ...props,
    },
  })

  return wrapper
}

const triggers = (wrapper: ReturnType<typeof mountField>) => wrapper.findAll('[role="tab"]')

describe('LocalizedField', () => {
  it('has a tab per language, marks the filled ones and the required one', () => {
    const wrapper = mountField([{ locale: 'ru', text: 'Привет' }], { requiredLabel: 'required' })
    const [ru, en] = triggers(wrapper)

    expect(ru.text()).toContain('RU')
    expect(ru.text()).toContain('required')
    expect(ru.find('.bg-success-dot').exists()).toBe(true)
    expect(en.text()).not.toContain('required')
    expect(en.find('.bg-success-dot').exists()).toBe(false)
  })

  it('edits the text of the open language and names the area with it', async () => {
    const wrapper = mountField([{ locale: 'ru', text: '' }], { label: 'Message', name: 'message' })
    const area = wrapper.find('textarea#message-ru')

    expect(area.attributes('aria-label')).toBe('Message (RU)')
    expect(area.attributes('name')).toBe('message.ru')

    await area.setValue('Привет')

    expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toEqual([{ locale: 'ru', text: 'Привет' }])
  })

  it('opens the tab of a language with an error and ties the error to its area', async () => {
    const wrapper = mountField([])

    await wrapper.setProps({ errors: { en: 'Too long' } })
    await nextTick()
    await new Promise((done) => setTimeout(done, 10))

    const [ru, en] = triggers(wrapper)
    expect(en.attributes('aria-selected')).toBe('true')
    expect(en.attributes('aria-invalid')).toBe('true')
    expect(ru.attributes('aria-invalid')).toBe('false')

    const area = wrapper.find('textarea#message-en')
    expect(area.attributes('aria-invalid')).toBe('true')
    expect(area.attributes('aria-describedby')).toBe('message-en-error')
    expect(wrapper.find('#message-en-error').text()).toBe('Too long')

    wrapper.unmount()
  })

  it('lets another tab open while an error stands, and moves only when the errors change', async () => {
    const wrapper = mountField([], { languages: [{ code: 'ru' }, { code: 'en' }, { code: 'uk' }] })
    const settle = async () => {
      await nextTick()
      await new Promise((done) => setTimeout(done, 10))
    }

    await wrapper.setProps({ errors: { en: 'Too long' } })
    await settle()
    expect(triggers(wrapper)[1].attributes('aria-selected')).toBe('true')

    await triggers(wrapper)[0].trigger('mousedown', { button: 0 })
    await settle()
    expect(triggers(wrapper)[0].attributes('aria-selected')).toBe('true')

    await wrapper.setProps({ errors: { en: 'Too long', uk: 'Empty' } })
    await settle()
    expect(triggers(wrapper)[1].attributes('aria-selected')).toBe('true')

    wrapper.unmount()
  })

  it('opens the tab with an error again after a repeated submit with the same errors', async () => {
    const wrapper = mountField([], { languages: [{ code: 'ru' }, { code: 'en' }] })
    const settle = async () => {
      await nextTick()
      await new Promise((done) => setTimeout(done, 10))
    }

    await wrapper.setProps({ errors: { en: 'Too long' } })
    await settle()
    await triggers(wrapper)[0].trigger('mousedown', { button: 0 })
    await settle()
    expect(triggers(wrapper)[0].attributes('aria-selected')).toBe('true')

    await wrapper.setProps({ errors: { en: 'Too long' } })
    await settle()
    expect(triggers(wrapper)[1].attributes('aria-selected')).toBe('true')

    wrapper.unmount()
  })
})
