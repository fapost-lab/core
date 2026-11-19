// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { defineComponent } from 'vue'
import CredentialForm from './CredentialForm.vue'
import type { CredentialTypeSchema, EditableCredential } from './types'

const { page } = vi.hoisted(() => ({ page: { props: {} as Record<string, unknown>, url: '/admin/credentials' } }))

vi.mock('@inertiajs/vue3', async (importOriginal) => ({
  ...(await importOriginal<object>()),
  usePage: () => page,
  Link: defineComponent({ props: { href: String }, template: '<a :href="href"><slot /></a>' }),
}))

page.props = {
  locale: 'en',
  translations: {
    console: {
      form: { save: 'Save', saving: 'Saving…', cancel: 'Cancel' },
      credentials: {
        sections: {
          general: { title: 'General', description: 'g' },
          secret: { title: 'Connection', description: 's' },
          hosts: { title: 'Allowed hosts', description: 'h' },
        },
        fields: {
          name: 'Name',
          type: 'Type',
          type_locked: 'Locked',
          hosts: 'Hosts',
          hosts_placeholder: 'host',
          hosts_remove: 'Remove :host',
          secret_saved: 'Saved. Leave empty to keep.',
          secret_placeholder: '•••• saved',
          secret_changed: 'Changed :date.',
          choose: 'Choose',
        },
        warnings: { api_key_query: 'Visible in the URL.' },
        type_hint: 'Choose a type.',
      },
    },
  },
}

const types: CredentialTypeSchema[] = [
  { id: 'bearer', label: 'Bearer token', fields: [{ key: 'token', label: 'Token', secret: true, required: true, kind: 'text', choices: [] }] },
  {
    id: 'api_key_query',
    label: 'API key in the query string',
    fields: [
      { key: 'parameter', label: 'Parameter name', secret: false, required: true, kind: 'text', choices: [] },
      { key: 'key', label: 'API key', secret: true, required: true, kind: 'text', choices: [] },
    ],
  },
  {
    id: 'oauth',
    label: 'OAuth',
    fields: [
      { key: 'token_url', label: 'Token URL', secret: false, required: true, kind: 'url', choices: [] },
      { key: 'client_secret', label: 'Client secret', secret: true, required: true, kind: 'text', choices: [] },
    ],
  },
]

function mountForm(credential?: EditableCredential) {
  return mount(CredentialForm, {
    props: { mode: credential ? 'edit' : 'create', method: credential ? 'put' : 'post', submitUrl: '/admin/credentials', cancelUrl: '/admin/credentials', types, credential },
    global: { stubs: { Select: true, SelectTrigger: true, SelectContent: true, SelectItem: true, SelectValue: true } },
  })
}

describe('CredentialForm', () => {
  it('renders no type fields until a type is chosen', () => {
    const wrapper = mountForm()

    expect(wrapper.find('input[type="password"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Choose a type.')
  })

  it('renders the fields of the stored type from the schema, secrets as password inputs', () => {
    const wrapper = mountForm({
      id: '1',
      name: 'CRM',
      type: 'api_key_query',
      type_label: 'API key in the query string',
      settings: { parameter: 'api_key' },
      allowed_hosts: ['api.example.com'],
      secret_changed_at: '2026-11-01T10:00:00+00:00',
    })

    expect((wrapper.find('#setting-parameter').element as HTMLInputElement).value).toBe('api_key')
    expect(wrapper.find('#setting-parameter').attributes('type')).toBe('text')

    const secret = wrapper.find('#secret-key')
    expect(secret.attributes('type')).toBe('password')
    expect(secret.attributes('autocomplete')).toBe('new-password')
    expect(secret.attributes('required')).toBeUndefined()
  })

  it('starts a secret field empty when editing, and says that a value is stored', () => {
    const wrapper = mountForm({
      id: '1',
      name: 'CRM',
      type: 'bearer',
      type_label: 'Bearer token',
      settings: {},
      allowed_hosts: ['api.example.com'],
      secret_changed_at: '2026-11-01T10:00:00+00:00',
    })

    const secret = wrapper.find('#secret-token')

    expect((secret.element as HTMLInputElement).value).toBe('')
    expect(secret.attributes('placeholder')).toBe('•••• saved')
    expect(wrapper.text()).toContain('Saved. Leave empty to keep.')
  })

  it('warns that a key in the URL is visible, only for that type', () => {
    const query = mountForm({
      id: '1',
      name: 'Q',
      type: 'api_key_query',
      type_label: 'x',
      settings: {},
      allowed_hosts: ['a.example.com'],
      secret_changed_at: '2026-11-01T10:00:00+00:00',
    })
    const bearer = mountForm({ id: '2', name: 'B', type: 'bearer', type_label: 'x', settings: {}, allowed_hosts: ['a.example.com'], secret_changed_at: '2026-11-01T10:00:00+00:00' })

    expect(query.find('[data-testid="query-key-warning"]').exists()).toBe(true)
    expect(bearer.find('[data-testid="query-key-warning"]').exists()).toBe(false)
  })

  it('renders a url field as a url input', () => {
    const wrapper = mountForm({ id: '1', name: 'O', type: 'oauth', type_label: 'OAuth', settings: { token_url: 'https://auth.example.com/token' }, allowed_hosts: ['a.example.com'], secret_changed_at: '2026-11-01T10:00:00+00:00' })

    expect(wrapper.find('#setting-token_url').attributes('type')).toBe('url')
  })
})
