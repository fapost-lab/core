// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'
import SolutionUnavailable from '../../pages/Errors/SolutionUnavailable.vue'
import AppRail from './AppRail.vue'
import type { ShellApplication } from './types'

const { page } = vi.hoisted(() => ({ page: { props: {} as Record<string, unknown>, url: '/apps/feedback/board' } }))

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  usePage: () => page,
  Head: defineComponent({ setup: () => () => null }),
  Link: defineComponent({ props: { href: String }, setup: (props, { slots, attrs }) => () => h('a', { ...attrs, href: props.href }, slots.default?.()) }),
}))

const app = (key: string, label: string, needsUpdate = false): ShellApplication => ({
  key,
  label,
  short: label,
  scope: '',
  icon: 'circle',
  href: `/${key}`,
  external: false,
  perAssistant: false,
  needsUpdate,
})

beforeEach(() => {
  page.props = {
    applications: { current: 'feedback', items: [app('admin', 'Admin'), app('feedback', 'Feedback', true)], rail: ['admin'], railUrl: '/rail' },
    translations: {
      console: {
        applications: { rail: 'Applications', all: 'All applications', needs_update: 'Needs an update' },
        solution_unavailable: {
          title: 'This page is unavailable',
          description: 'Not active or failed to load.',
          back: 'Go back',
          update_required: { title: 'This application needs an update', description: 'Its interface was built for an earlier version.' },
        },
      },
    },
  }
})

describe('a Solution whose interface needs an update', () => {
  it('is marked in the rail and named so, while other applications are not', () => {
    const rail = mount(AppRail, { global: { stubs: { AllApplicationsDialog: true } } })

    expect(rail.find('[data-test="rail-needs-update-feedback"]').exists()).toBe(true)
    expect(rail.get('[data-test="rail-feedback"]').attributes('aria-label')).toBe('Feedback · Needs an update')
    expect(rail.find('[data-test="rail-needs-update-admin"]').exists()).toBe(false)
    expect(rail.get('[data-test="rail-admin"]').attributes('aria-label')).toBe('Admin')
  })

  it('opens on a page that says an update is needed', () => {
    const update = mount(SolutionUnavailable, { props: { reason: 'update_required' } })

    expect(update.find('[data-test="solution-update-required"]').exists()).toBe(true)
    expect(update.text()).toContain('This application needs an update')
  })

  it('keeps the generic text when the page could not be loaded in the browser', () => {
    const unavailable = mount(SolutionUnavailable)

    expect(unavailable.find('[data-test="solution-unavailable"]').exists()).toBe(true)
    expect(unavailable.text()).toContain('This page is unavailable')
  })
})
