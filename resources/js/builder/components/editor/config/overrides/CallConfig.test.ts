// @vitest-environment happy-dom
import {beforeEach, describe, expect, it, vi} from 'vitest'
import {flushPromises, mount} from '@vue/test-utils'
import {createPinia, setActivePinia} from 'pinia'


const {pageProps} = vi.hoisted(() => ({pageProps: {
    auth:         {permissions: [] as string[]},
    translations: {builder: {call: {auth: {
        bearer:                 'Bearer token (stored in the flow definition)',
        credential_unavailable: 'Unavailable credential',
        no_credentials:         'No saved credentials yet. Add one in Administration → System → Credentials.',
    }}}},
}}))

vi.mock('@inertiajs/vue3', () => ({usePage: () => ({props: pageProps})}))

function grant(permissions: string[]): void {
    pageProps.auth.permissions = permissions
}

const {fetchCredentialOptions} = vi.hoisted(() => ({fetchCredentialOptions: vi.fn()}))

vi.mock('@builder/api/builderApi', async (importOriginal) => ({
    ...(await importOriginal<object>()),
    fetchCredentialOptions,
}))

import CallConfig from './CallConfig.vue'

const credentials = [
    {id: 'c-1', name: 'CRM', type: 'bearer'},
    {id: 'c-2', name: 'Billing', type: 'basic'},
]

async function mountConfig(config: Record<string, unknown>) {
    const wrapper = mount(CallConfig, {
        props: {node: {id: 'n1', type: 'call', config}, schema: {}},
        global: {stubs: {VariableStorageEditor: true, TemplateInput: true, SearchableSelect: true, AccordionSection: {template: '<section><slot /></section>'}}},
    })
    await flushPromises()

    return wrapper
}

function lastPatch(wrapper: Awaited<ReturnType<typeof mountConfig>>): Record<string, unknown> {
    const events = wrapper.emitted('update:config') ?? []

    return events[events.length - 1][0] as Record<string, unknown>
}

describe('CallConfig authorization', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
        grant(['use_credentials'])
        fetchCredentialOptions.mockReset()
        fetchCredentialOptions.mockResolvedValue(credentials)
    })

    it('offers the saved credentials and writes the chosen id', async () => {
        const wrapper = await mountConfig({transport: 'http', target: 'GET https://api.example.com/x', parameters: {}})

        await wrapper.get('[data-testid="auth-mode"]').setValue('credential')
        expect(wrapper.findAll('[data-testid="credential-select"] option').map((o) => o.text())).toEqual(['—', 'CRM', 'Billing'])

        await wrapper.get('[data-testid="credential-select"]').setValue('c-2')
        expect(lastPatch(wrapper).credential_id).toBe('c-2')
    })

    it('removes the id when another mode is chosen', async () => {
        const wrapper = await mountConfig({transport: 'http', target: 'GET https://api.example.com/x', parameters: {}, credential_id: 'c-1'})

        expect((wrapper.get('[data-testid="auth-mode"]').element as HTMLSelectElement).value).toBe('credential')

        await wrapper.get('[data-testid="auth-mode"]').setValue('none')
        const patch = lastPatch(wrapper)

        expect('credential_id' in patch).toBe(true)
        expect(patch.credential_id).toBeUndefined()
    })

    it('keeps an inline bearer working and warns that it is stored in the flow', async () => {
        const wrapper = await mountConfig({transport: 'http', target: 'GET https://api.example.com/x', parameters: {'auth.bearer': 'abc'}})

        expect((wrapper.get('[data-testid="auth-mode"]').element as HTMLSelectElement).value).toBe('bearer')
        expect(wrapper.text()).toContain('stored in the flow definition')
        expect(wrapper.find('[data-testid="credential-select"]').exists()).toBe(false)
    })

    it('has no credential picker for the handler transport and drops the id', async () => {
        const wrapper = await mountConfig({transport: 'handler', target: 'crm.create', parameters: {}, credential_id: 'c-1'})

        expect(wrapper.find('[data-testid="auth-mode"]').exists()).toBe(false)

        await wrapper.get('select.field-input').setValue('handler')
        expect(lastPatch(wrapper).credential_id).toBeUndefined()
    })

    it('shows a saved id the list does not have instead of clearing it', async () => {
        fetchCredentialOptions.mockResolvedValue([])
        const wrapper = await mountConfig({transport: 'http', target: 'GET https://api.example.com/x', parameters: {}, credential_id: 'gone'})

        expect(wrapper.text()).toContain('Unavailable credential')
        expect(wrapper.text()).toContain('No saved credentials yet')
    })
})

describe('CallConfig without the Use credentials permission', () => {
    const http = {transport: 'http', target: 'GET https://api.example.com/x', parameters: {}}

    beforeEach(() => {
        setActivePinia(createPinia())
        fetchCredentialOptions.mockReset()
        fetchCredentialOptions.mockResolvedValue(credentials)
        grant([])
    })

    it('does not list the credentials and offers no saved-credential mode', async () => {
        const wrapper = await mountConfig(http)

        expect(fetchCredentialOptions).not.toHaveBeenCalled()
        expect(wrapper.get('[data-testid="auth-mode"] option[value="credential"]').attributes('disabled')).toBeDefined()
    })

    it('keeps a stored credential visible and read-only', async () => {
        const wrapper = await mountConfig({...http, credential_id: 'c-1'})

        expect(wrapper.get('[data-testid="auth-mode"]').attributes('disabled')).toBeDefined()
        expect(wrapper.get('[data-testid="credential-select"]').attributes('disabled')).toBeDefined()
        expect(wrapper.get('[data-testid="credential-select"]').text()).toContain('permission_hints.credential_locked')
        expect(wrapper.get('[data-testid="credential-permission-hint"]').text()).toContain('permission_hints.credential_change')
    })

    it('disables the test button for a call that carries a credential', async () => {
        const blocked = await mountConfig({...http, credential_id: 'c-1'})
        expect(blocked.get('[data-testid="test-btn"]').attributes('disabled')).toBeDefined()
        expect(blocked.find('[data-testid="test-permission-hint"]').exists()).toBe(true)

        const free = await mountConfig(http)
        expect(free.get('[data-testid="test-btn"]').attributes('disabled')).toBeUndefined()
    })
})

describe('CallConfig retry', () => {
    const http = {transport: 'http', target: 'GET https://api.example.com/x', parameters: {}}

    beforeEach(() => {
        setActivePinia(createPinia())
        grant(['use_credentials'])
        fetchCredentialOptions.mockReset()
        fetchCredentialOptions.mockResolvedValue([])
    })

    it('shows a stored retry and writes it back in the same shape', async () => {
        const retry = {max_attempts: 4, delay_ms: 1000, backoff: 'fixed', on: ['5xx']}
        const wrapper = await mountConfig({...http, retry})

        expect((wrapper.get('[data-testid="retry-enabled"]').element as HTMLInputElement).checked).toBe(true)
        expect((wrapper.get('[data-testid="retry-attempts"]').element as HTMLInputElement).value).toBe('4')
        expect((wrapper.get('[data-testid="retry-on-5xx"]').element as HTMLInputElement).checked).toBe(true)
        expect((wrapper.get('[data-testid="retry-on-network"]').element as HTMLInputElement).checked).toBe(false)

        await wrapper.get('[data-testid="retry-on-429"]').setValue(true)

        expect(lastPatch(wrapper).retry).toEqual({max_attempts: 4, delay_ms: 1000, backoff: 'fixed', on: ['5xx', '429']})
    })

    it('writes the defaults when retry is switched on and no retry key while it is off', async () => {
        const wrapper = await mountConfig(http)

        expect(wrapper.find('[data-testid="retry-attempts"]').exists()).toBe(false)

        await wrapper.get('[data-testid="retry-enabled"]').setValue(true)
        expect(lastPatch(wrapper).retry).toEqual({max_attempts: 3, delay_ms: 500, backoff: 'exponential', on: ['network', '5xx', '429']})

        await wrapper.get('[data-testid="retry-enabled"]').setValue(false)
        const patch = lastPatch(wrapper)
        expect('retry' in patch).toBe(true)
        expect(patch.retry).toBeUndefined()
    })

    it('clamps the attempts to the allowed range', async () => {
        const wrapper = await mountConfig({...http, retry: {max_attempts: 3, delay_ms: 500, backoff: 'exponential', on: ['network']}})

        await wrapper.get('[data-testid="retry-attempts"]').setValue('9')

        expect((lastPatch(wrapper).retry as Record<string, unknown>).max_attempts).toBe(5)
        expect((wrapper.get('[data-testid="retry-attempts"]').element as HTMLInputElement).value).toBe('5')
    })

    it('keeps the last checked status: it cannot be cleared', async () => {
        const wrapper = await mountConfig({...http, retry: {max_attempts: 3, delay_ms: 500, backoff: 'exponential', on: ['network']}})
        const emittedBefore = (wrapper.emitted('update:config') ?? []).length

        await wrapper.get('[data-testid="retry-on-network"]').setValue(false)

        expect((wrapper.get('[data-testid="retry-on-network"]').element as HTMLInputElement).checked).toBe(true)
        expect((wrapper.emitted('update:config') ?? []).length).toBe(emittedBefore)
    })
})
