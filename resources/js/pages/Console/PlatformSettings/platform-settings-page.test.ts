// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, reactive } from 'vue'
import Edit from './Edit.vue'
import type { PlatformSettingsGroup } from './types'

const { page, put } = vi.hoisted(() => ({ page: { props: {} as Record<string, unknown>, url: '/admin/platform-settings' }, put: vi.fn() }))

/** A stand-in for Inertia's form: keeps the data, applies the registered transform on put and reports what would be sent. */
function fakeForm(initial: Record<string, unknown>) {
  let transform: (data: Record<string, unknown>) => Record<string, unknown> = (data) => data
  const form = reactive({
    ...initial,
    errors: {},
    processing: false,
    transform(callback: typeof transform) {
      transform = callback
      return form
    },
    put(url: string) {
      const { errors, processing, transform: _t, put: _p, ...data } = form as Record<string, unknown>
      put(url, transform(data))
    },
  })

  return form
}

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  usePage: () => page,
  useForm: (initial: Record<string, unknown>) => fakeForm(initial),
  Head: defineComponent({ setup: () => () => null }),
}))

const translations = {
  console: {
    form: { save: 'Save', saving: 'Saving' },
    platform_settings: {
      title: 'Platform settings',
      description: 'Settings.',
      saved: 'Saved',
      default: 'Default: :value',
      stored: 'Stored',
      not_stored: 'Default',
      use_default: 'Use default',
      default_option: 'Use default',
      range: ':min to :max',
      min: 'From :min',
      max: 'Up to :max',
      on: 'On',
      off: 'Off',
      groups: {},
      fields: {},
    },
  },
}

const groups: PlatformSettingsGroup[] = [
  {
    name: 'Flow',
    settings: [
      { key: 'flow.execution.max_iterations', field: 'flow_execution_max_iterations', type: 'integer', label: 'Max iterations', description: '', unit: null, min: 10, max: 10000, choices: [], default: 100, stored: true, value: 250 },
      { key: 'solutions.marketplace_url', field: 'solutions_marketplace_url', type: 'text', label: 'Marketplace URL', description: '', unit: null, min: null, max: null, choices: [], default: 'https://x.test', stored: true, value: '' },
    ],
  },
]

function mountPage() {
  return mount(Edit, { props: { groups, urls: { submit: '/admin/platform-settings' } } })
}

describe('Platform settings page', () => {
  beforeEach(() => {
    put.mockReset()
    page.props = { translations }
  })

  it('sends only the changed field and leaves an untouched empty value out', async () => {
    const wrapper = mountPage()

    await wrapper.find('#flow_execution_max_iterations').setValue('300')
    await wrapper.find('form').trigger('submit')

    expect(put).toHaveBeenCalledWith('/admin/platform-settings', { flow_execution_max_iterations: '300' })
  })

  it('sends a cleared field as null', async () => {
    const wrapper = mountPage()

    await wrapper.find('#flow_execution_max_iterations').setValue('')
    await wrapper.find('form').trigger('submit')

    expect(put).toHaveBeenCalledWith('/admin/platform-settings', { flow_execution_max_iterations: null })
  })

  it('compares with the values stored by the last save, not those the page opened with', async () => {
    const withIterations = (stored: boolean, value: number | null): PlatformSettingsGroup[] => [
      { ...groups[0], settings: [{ ...groups[0].settings[0], stored, value }, groups[0].settings[1]] },
    ]
    const wrapper = mount(Edit, { props: { groups: withIterations(false, null), urls: { submit: '/admin/platform-settings' } } })

    await wrapper.find('#flow_execution_max_iterations').setValue('500')
    await wrapper.find('form').trigger('submit')
    await wrapper.setProps({ groups: withIterations(true, 500) })

    await wrapper.find('#flow_execution_max_iterations').setValue('')
    await wrapper.find('form').trigger('submit')

    expect(put).toHaveBeenLastCalledWith('/admin/platform-settings', { flow_execution_max_iterations: null })
  })
})
