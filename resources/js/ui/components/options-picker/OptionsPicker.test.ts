// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import OptionsPicker from './OptionsPicker.vue'

const options = [
  { value: 'UA', label: 'Ukraine' },
  { value: 'DE', label: 'Germany' },
]

function mountPicker(selected: string[], props: Record<string, unknown> = {}) {
  const wrapper = mount(OptionsPicker, {
    attachTo: document.body,
    props: {
      id: 'countries',
      options,
      modelValue: selected,
      'onUpdate:modelValue': (next: string[]) => wrapper.setProps({ modelValue: next }),
      placeholder: 'Pick countries',
      emptyLabel: 'Nothing found',
      ...props,
    },
  })

  return wrapper
}

const settle = () => new Promise((done) => setTimeout(done, 10))

async function open(wrapper: ReturnType<typeof mountPicker>): Promise<void> {
  await wrapper.find('button#countries').trigger('keydown', { key: 'Enter' })
  await settle()
}

const items = () => [...document.body.querySelectorAll<HTMLElement>('[role="menuitemcheckbox"]')]

afterEach(() => {
  document.body.innerHTML = ''
})

describe('OptionsPicker', () => {
  it('shows the placeholder, then the count once something is chosen', () => {
    expect(mountPicker([]).find('button#countries').text()).toBe('Pick countries')
    expect(mountPicker(['UA'], { selectedLabel: (count: number) => `Selected: ${count}` }).find('button#countries').text()).toBe('Selected: 1')
    expect(mountPicker(['UA']).find('button#countries').text()).toBe('Pick countries')
  })

  it('passes the error state to its button', () => {
    const button = mountPicker([], { invalid: true, describedBy: 'countries-error' }).find('button#countries')

    expect(button.attributes('aria-invalid')).toBe('true')
    expect(button.attributes('aria-describedby')).toBe('countries-error')
  })

  it('ticks an offered option from the menu and keeps the menu open', async () => {
    const wrapper = mountPicker(['DE'])
    await open(wrapper)

    expect(items().map((item) => item.textContent?.trim())).toEqual(['Ukraine', 'Germany'])
    expect(items()[1].getAttribute('aria-checked')).toBe('true')

    items()[0].click()
    await settle()

    expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toEqual(['DE', 'UA'])
    expect(items()).toHaveLength(2)
  })

  it('takes plain strings as options', async () => {
    const wrapper = mountPicker([], { options: ['vip', 'new'] })
    await open(wrapper)

    expect(items().map((item) => item.textContent?.trim())).toEqual(['vip', 'new'])
  })

  it('filters by the search field when it has one', async () => {
    const wrapper = mountPicker([], { searchLabel: 'Search' })
    await open(wrapper)

    const search = document.body.querySelector<HTMLInputElement>('input[aria-label="Search"]')!
    search.value = 'germ'
    search.dispatchEvent(new Event('input'))
    await settle()

    expect(items().map((item) => item.textContent?.trim())).toEqual(['Germany'])
  })

  it('says so when there is nothing to choose', async () => {
    const wrapper = mountPicker([], { options: [] })
    await open(wrapper)

    expect(document.body.textContent).toContain('Nothing found')
  })

  it('shows the chosen as chips, removable only with a remove label', async () => {
    expect(mountPicker(['UA']).findAll('button[aria-label]')).toHaveLength(0)

    const wrapper = mountPicker(['UA', 'DE'], { removeLabel: (label: string) => `Remove ${label}` })
    expect(wrapper.text()).toContain('Ukraine')

    await wrapper.find('button[aria-label="Remove Ukraine"]').trigger('click')

    expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toEqual(['DE'])
  })

  it('shows a chosen value the list lacks as a danger chip', () => {
    const chips = mountPicker(['UA', 'gone'], { missingLabel: 'Deleted group' }).findAll('[data-slot="badge"]')

    expect(chips.map((chip) => chip.text())).toEqual(['Ukraine', 'Deleted group'])
    expect(chips[0].classes()).toContain('bg-neutral')
    expect(chips[1].classes()).toContain('bg-danger')
  })
})
