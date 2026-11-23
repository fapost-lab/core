// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import OAuthConnection from './OAuthConnection.vue'
import type { OAuthConnectionState } from './types'

const { page, router } = vi.hoisted(() => ({
  page: { props: {} as Record<string, unknown>, url: '/admin/credentials/1/edit' },
  router: { post: vi.fn(), delete: vi.fn() },
}))

vi.mock('@inertiajs/vue3', async (importOriginal) => ({ ...(await importOriginal<object>()), usePage: () => page, router }))

page.props = {
  locale: 'en',
  translations: {
    console: {
      form: { cancel: 'Cancel' },
      credentials: {
        oauth: {
          title: 'Connection',
          description: 'd',
          statuses: { not_connected: 'Not connected', connected: 'Connected', reconnect_needed: 'Reconnect needed' },
          connected_by: 'Connected :date by :name.',
          connected_at: 'Connected :date.',
          refreshed: 'Refreshed :date.',
          reconnect_hint: 'Connect again.',
          failure: '(:code)',
          callback_url: 'Callback URL',
          callback_hint: 'hint',
          copy: 'Copy',
          copied: 'Copied',
          connect: 'Connect',
          reconnect: 'Reconnect',
          disconnect: 'Disconnect',
          disconnect_confirm: { title: 'Disconnect?', description: 'x' },
          save_first: 'Save first.',
          params_hint: 'params',
        },
      },
    },
  },
}

function state(overrides: Partial<OAuthConnectionState> = {}): OAuthConnectionState {
  return {
    status: 'not_connected',
    connected_at: null,
    connected_by_name: null,
    refreshed_at: null,
    failure_code: null,
    callback_url: 'https://acme.example.com/admin/credentials/oauth/callback',
    urls: { connect: '/connect', disconnect: '/disconnect' },
    ...overrides,
  }
}

const mountBlock = (oauth: OAuthConnectionState) => mount(OAuthConnection, { props: { oauth }, global: { stubs: { ConfirmDialog: true } } })

describe('OAuthConnection', () => {
  beforeEach(() => vi.clearAllMocks())

  it('offers only Connect while not connected, and shows the callback URL', () => {
    const wrapper = mountBlock(state())

    expect(wrapper.text()).toContain('Not connected')
    expect(wrapper.find('[data-testid="oauth-connect"]').text()).toBe('Connect')
    expect(wrapper.find('[data-testid="oauth-disconnect"]').exists()).toBe(false)
    expect((wrapper.find('#oauth-callback-url').element as HTMLInputElement).value).toBe('https://acme.example.com/admin/credentials/oauth/callback')
  })

  it('shows who connected and offers Reconnect and Disconnect once connected', () => {
    const wrapper = mountBlock(state({ status: 'connected', connected_at: '2026-11-01T10:00:00+00:00', connected_by_name: 'Ada', refreshed_at: '2026-11-02T10:00:00+00:00' }))

    expect(wrapper.text()).toContain('by Ada')
    expect(wrapper.text()).toContain('Refreshed')
    expect(wrapper.find('[data-testid="oauth-connect"]').text()).toBe('Reconnect')
    expect(wrapper.find('[data-testid="oauth-disconnect"]').exists()).toBe(true)
  })

  it('shows the failure code when the provider refused a refresh', () => {
    const wrapper = mountBlock(state({ status: 'reconnect_needed', failure_code: 'invalid_grant' }))

    expect(wrapper.find('[data-testid="oauth-failure"]').text()).toContain('(invalid_grant)')
    expect(wrapper.find('[data-testid="oauth-connect"]').text()).toBe('Reconnect')
  })

  it('posts to the connect URL', async () => {
    const wrapper = mountBlock(state())

    await wrapper.find('[data-testid="oauth-connect"]').trigger('click')

    expect(router.post).toHaveBeenCalledWith('/connect', {}, expect.any(Object))
  })
})
