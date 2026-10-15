// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ValueListInput from './ValueListInput.vue'

function mountInput(values: string[], props: Record<string, unknown> = {}) {
  const wrapper = mount(ValueListInput, {
    props: {
      id: 'tags',
      modelValue: values,
      'onUpdate:modelValue': (next: string[]) => wrapper.setProps({ modelValue: next }),
      removeLabel: (value: string) => `Remove ${value}`,
      ...props,
    },
  })

  return wrapper
}

const last = (wrapper: ReturnType<typeof mountInput>) => wrapper.emitted('update:modelValue')?.at(-1)?.[0]

describe('ValueListInput', () => {
  it('adds what was typed on Enter and on a comma', async () => {
    const wrapper = mountInput(['a'])
    const input = wrapper.find('input')

    await input.setValue('b, c')
    await input.trigger('keydown', { key: 'Enter' })
    expect(last(wrapper)).toEqual(['a', 'b', 'c'])
    expect((input.element as HTMLInputElement).value).toBe('')

    await input.setValue('d')
    await input.trigger('keydown', { key: ',' })
    expect(last(wrapper)).toEqual(['a', 'b', 'c', 'd'])
  })

  it('adds what was typed when the field loses focus, so a save still counts it', async () => {
    const wrapper = mountInput([])

    await wrapper.find('input').setValue('vip')
    await wrapper.find('input').trigger('blur')

    expect(last(wrapper)).toEqual(['vip'])
  })

  it('leaves the list alone when nothing was typed', async () => {
    const wrapper = mountInput(['a'])

    await wrapper.find('input').trigger('blur')

    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
  })

  it('removes a chip by its named button', async () => {
    const wrapper = mountInput(['a', 'b'])
    const remove = wrapper.find('button[aria-label="Remove a"]')

    await remove.trigger('click')

    expect(last(wrapper)).toEqual(['b'])
  })

  it('has an add button only when it is given a label', async () => {
    expect(mountInput([]).findAll('button')).toHaveLength(0)

    const wrapper = mountInput([], { addLabel: 'Add' })
    await wrapper.find('input').setValue('x')
    await wrapper.findAll('button').find((button) => button.text() === 'Add')!.trigger('click')

    expect(last(wrapper)).toEqual(['x'])
  })

  it('offers suggestions through a datalist tied to the field, and passes the error state on', () => {
    const wrapper = mountInput([], { suggestions: ['vip'], invalid: true, describedBy: 'tags-error' })
    const input = wrapper.find('input')

    expect(input.attributes('list')).toBe('tags-suggestions')
    expect(wrapper.find('datalist#tags-suggestions option').attributes('value')).toBe('vip')
    expect(input.attributes('aria-invalid')).toBe('true')
    expect(input.attributes('aria-describedby')).toBe('tags-error')
  })

  it('adds the draft when the form calls commit itself', async () => {
    const wrapper = mountInput(['a'])

    await wrapper.find('input').setValue('b')
    ;(wrapper.vm as unknown as { commit: () => void }).commit()

    expect(last(wrapper)).toEqual(['a', 'b'])
  })
})
