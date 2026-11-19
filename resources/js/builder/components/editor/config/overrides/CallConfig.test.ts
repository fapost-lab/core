// @vitest-environment happy-dom
import {beforeEach, describe, expect, it, vi} from 'vitest'
import {flushPromises, mount} from '@vue/test-utils'
import {createPinia, setActivePinia} from 'pinia'

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
