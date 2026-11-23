// @vitest-environment happy-dom
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { computed, defineComponent } from 'vue'
import Show from './Show.vue'
import type { FlowSessionDetails, RerunOffer, RerunRow, RerunSource } from './types'

const { page, post, live } = vi.hoisted(() => ({
  page: { props: {} as Record<string, unknown>, url: '/assistant/a/flow-sessions/s' },
  post: vi.fn(),
  live: { options: null as null | { active: () => boolean; only: string[] } },
}))

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  usePage: () => page,
  router: { post },
  Head: defineComponent({ setup: () => () => null }),
}))

vi.mock('@console/lib/useLiveUpdates', () => ({
  useLiveUpdates: (options: { active: () => boolean; only: string[] }) => {
    live.options = options
    return { transport: computed(() => 'polling') }
  },
}))

const translations = {
  console: {
    form: { cancel: 'Cancel' },
    contacts: { copy: 'Copy', copied: 'Copied' },
    flow_sessions: {
      back: 'Flow sessions',
      template_node: 'Template',
      statuses: { failed: 'Failed', completed: 'Completed', active: 'Active' },
      end_statuses: {},
      live: { live: 'Live', polling: 'Polling' },
      view: {
        identity: 'Session', status: 'Status', end_status: 'Outcome', current_node: 'Current node', version: 'Revision', flow: 'Flow',
        flow_version: 'Flow version', contact: 'Contact', created_at: 'Started', updated_at: 'Updated', parent: 'Subflow of',
        parent_session: 'Parent session', parent_resume_node: 'Resumes', expires_at: 'Expires', state: 'State', state_empty: 'Empty',
        history: 'History', history_note: 'Latest :count', history_empty: 'Nothing', logs: 'Log', graph: 'Run graph', empty_value: '—',
      },
      rerun: {
        action: 'Run again',
        confirm_title: 'Run again from the start?',
        confirm_description: 'Everything is sent again. It runs flow version :version.',
        version_changed: 'This run ran version :source.',
        confirm: 'Run again now',
        queued: 'Queued',
        refusals: {
          not_an_automation: 'Not an automation',
          not_a_root: 'Not a root',
          not_failed: 'Only a failed run can be run again.',
          no_payload: 'No payload',
          trigger_gone: 'Trigger gone',
          no_active_version: 'No active version',
        },
        reruns: 'Re-runs',
        reruns_description: 'Runs started again from this one.',
        rerun_of: 'Re-run of',
      },
    },
  },
}

const session: FlowSessionDetails = {
  id: 'source-run',
  status: 'failed',
  endStatus: null,
  currentNodeId: 'call',
  currentNodeOrigin: null,
  version: 4,
  flowName: 'Orders',
  flowVersion: 2,
  contactExternalId: null,
  createdAt: null,
  updatedAt: null,
  parentId: null,
  parentResumeNodeId: null,
  expiresAt: null,
  isLive: false,
}

const offer = (over: Partial<RerunOffer> = {}): RerunOffer => ({ allowed: true, refusal: null, targetVersion: 2, attempt: 3, url: '/rerun-url', ...over })

function mountPage(rerun: RerunOffer | null, reruns: RerunRow[] = [], rerunOf: RerunSource | null = null) {
  return mount(Show, {
    attachTo: document.body,
    props: { session, state: [], history: null, rerun, reruns, rerunOf, live: null, urls: { index: '/index', graph: '/graph' } },
  })
}

beforeEach(() => {
  page.props = { translations, locale: 'en' }
  post.mockReset()
})

afterEach(() => {
  document.body.innerHTML = ''
})

describe('re-running a run from its page', () => {
  it('shows no button to a user who may not re-run', () => {
    expect(mountPage(null).find('[data-test="rerun"]').exists()).toBe(false)
  })

  it('shows a disabled button with the reason when the run cannot be re-run, and sends nothing', async () => {
    const wrapper = mountPage(offer({ allowed: false, refusal: 'not_failed', targetVersion: null }))
    const button = wrapper.get('[data-test="rerun"]')

    expect(button.attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="rerun-refusal"]').text()).toBe('Only a failed run can be run again.')

    await button.trigger('click')
    await flushPromises()

    expect(document.body.querySelector('[role="alertdialog"]')).toBeNull()
    expect(post).not.toHaveBeenCalled()
  })

  it('asks first, warning that everything is sent again, and posts only once confirmed', async () => {
    const wrapper = mountPage(offer())

    await wrapper.get('[data-test="rerun"]').trigger('click')
    await flushPromises()

    const dialog = document.body.querySelector('[role="alertdialog"]')
    expect(dialog?.textContent).toContain('Everything is sent again. It runs flow version 2.')
    expect(dialog?.textContent).not.toContain('This run ran version')
    expect(post).not.toHaveBeenCalled()

    const confirm = Array.from(dialog?.querySelectorAll('button') ?? []).find((button) => button.textContent?.trim() === 'Run again now')
    confirm?.click()
    await flushPromises()

    expect(post).toHaveBeenCalledTimes(1)
    expect(post.mock.calls[0]?.[0]).toBe('/rerun-url')
    // The attempt the page offered goes back: a repeated request carries the same one and starts no second run.
    expect(post.mock.calls[0]?.[1]).toEqual({ attempt: 3 })
  })

  it('keeps reloading the page of a finished run while its queued re-run has not shown up, and stops once it has', async () => {
    vi.useFakeTimers()
    const wrapper = mountPage(offer())

    expect(live.options?.only).toEqual(expect.arrayContaining(['rerun', 'reruns']))
    expect(live.options?.active()).toBe(false)

    await wrapper.get('[data-test="rerun"]').trigger('click')
    await flushPromises()
    Array.from(document.body.querySelectorAll('[role="alertdialog"] button')).find((button) => button.textContent?.trim() === 'Run again now')?.dispatchEvent(new Event('click'))
    await flushPromises()
    post.mock.calls[0]?.[2]?.onSuccess?.()

    expect(live.options?.active()).toBe(true)

    await wrapper.setProps({ reruns: [{ id: 'rerun-1', status: 'completed', isRunning: false, createdAt: null, url: '/rerun-1' }] })
    expect(live.options?.active()).toBe(false)

    vi.useRealTimers()
  })

  it('keeps reloading while a listed re-run is still running', () => {
    mountPage(offer(), [{ id: 'rerun-1', status: 'active', isRunning: true, createdAt: null, url: '/rerun-1' }])

    expect(live.options?.active()).toBe(true)
  })

  it('says so when the re-run runs a newer version than the run did', async () => {
    const wrapper = mountPage(offer({ targetVersion: 3 }))

    await wrapper.get('[data-test="rerun"]').trigger('click')
    await flushPromises()

    expect(document.body.querySelector('[role="alertdialog"]')?.textContent).toContain('It runs flow version 3. This run ran version 2.')
  })

  it('lists the re-runs of the run and links a re-run back to its source', () => {
    const wrapper = mountPage(offer(), [{ id: 'rerun-1', status: 'completed', isRunning: false, createdAt: null, url: '/rerun-1' }], { id: 'older-run', url: '/older' })

    expect(wrapper.get('[data-test="reruns"]').text()).toContain('rerun-1')
    expect(wrapper.get('[data-test="reruns"]').text()).toContain('Completed')
    expect(wrapper.get('[data-test="rerun-of"] a').attributes('href')).toBe('/older')
  })
})
