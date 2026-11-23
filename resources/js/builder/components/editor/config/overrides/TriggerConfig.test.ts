// @vitest-environment happy-dom
import {beforeEach, describe, expect, it, vi} from 'vitest'
import {flushPromises, mount} from '@vue/test-utils'

vi.mock('@builder/api/builderApi', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@builder/api/builderApi')>()),
    fetchAssistantOptions: vi.fn(async () => [{value: 'a-sales', label: 'Sales'}, {value: 'a-support', label: 'Support'}]),
    resumeTrigger: vi.fn(async () => ({})),
}))


const {pageProps} = vi.hoisted(() => ({pageProps: {
    auth:         {permissions: [] as string[]},
    translations: {builder: {webhook_trigger: {get_url: 'Get URL', response_async: 'Accept at once (202)'}}},
}}))

vi.mock('@inertiajs/vue3', () => ({usePage: () => ({props: pageProps})}))

function grant(permissions: string[]): void {
    pageProps.auth.permissions = permissions
}

import TriggerConfig from './TriggerConfig.vue'
import SearchSelect from '../SearchSelect.vue'
import {resumeTrigger} from '@builder/api/builderApi'
import type {TriggerStateInfo} from '@builder/dto/types'

function mountEventTrigger(eventName: string, extra: Record<string, unknown> = {}) {
    return mount(TriggerConfig, {
        props: {
            trigger:         {type: 'event', is_active: true, priority: 0, config: {event_name: eventName, ...extra}},
            availableEvents: ['core.contact.created', 'core.contact.tag_added', 'orders.paid'],
        },
    })
}

describe('TriggerConfig event picker', () => {
    beforeEach(() => grant(['manage_automations']))

    it('lists Core events in a group of their own, before the flows\' events', async () => {
        const wrapper = mountEventTrigger('')

        await wrapper.find('.event-combo input').trigger('focus')

        const items = wrapper.findAll('.event-group, .event-option').map((node) => node.text())
        expect(items).toEqual(['Core', 'core.contact.created', 'core.contact.tag_added', 'Flow events', 'orders.paid'])
    })

    it('says a Core event starts the flow without a contact', () => {
        expect(mountEventTrigger('core.contact.created').find('[data-test=core-event-hint]').exists()).toBe(true)
        expect(mountEventTrigger('orders.paid').find('[data-test=core-event-hint]').exists()).toBe(false)
    })
})

describe('TriggerConfig assistant filter', () => {
    beforeEach(() => grant(['manage_automations']))

    function lastConfig(wrapper: ReturnType<typeof mountEventTrigger>): Record<string, unknown> {
        return (wrapper.emitted('update:trigger')?.at(-1)?.[0] as {config: Record<string, unknown>}).config
    }

    it('is offered for a flow event and not for a Core event', () => {
        expect(mountEventTrigger('orders.paid').find('[data-test=assistant-filter]').exists()).toBe(true)
        expect(mountEventTrigger('core.contact.created').find('[data-test=assistant-filter]').exists()).toBe(false)
    })

    it('shows the chosen assistants by name, and an id no longer among them as deleted', async () => {
        const wrapper = mountEventTrigger('orders.paid', {assistant_filter: ['a-sales', 'a-gone']})
        await flushPromises()

        const chips = wrapper.find('[data-test=assistant-filter]').text()
        expect(chips).toContain('Sales')
        expect(chips).toContain('Deleted assistant')
    })

    it('writes the chosen assistants and leaves the key out when none is chosen', () => {
        const wrapper = mountEventTrigger('orders.paid')
        const select = wrapper.findComponent(SearchSelect)

        select.vm.$emit('update:modelValue', ['a-sales'])
        expect(lastConfig(wrapper)).toEqual({event_name: 'orders.paid', assistant_filter: ['a-sales']})

        select.vm.$emit('update:modelValue', [])
        expect(lastConfig(wrapper)).toEqual({event_name: 'orders.paid'})
    })

    it('drops the filter when a Core event is picked', async () => {
        const wrapper = mountEventTrigger('', {assistant_filter: ['a-sales']})

        await wrapper.find('.event-combo input').trigger('focus')
        const core = wrapper.findAll('.event-option').find((node) => node.text() === 'core.contact.created')
        await core?.trigger('mousedown')

        expect(lastConfig(wrapper)).toEqual({event_name: 'core.contact.created'})
    })
})

describe('TriggerConfig webhook response mode', () => {
    beforeEach(() => grant(['manage_automations']))

    function mountWebhookTrigger(config: Record<string, unknown>) {
        return mount(TriggerConfig, {
            props: {
                trigger: {id: 'trigger-1', type: 'webhook', is_active: true, priority: 0, config},
            },
        })
    }

    const hmac = {verification: 'hmac_sha256', signature_header: 'X-Signature', signature_prefix: 'sha256='}

    it('shows the mode the trigger has, async by default', () => {
        expect((mountWebhookTrigger(hmac).find('[data-testid=webhook-response-mode]').element as HTMLSelectElement).value).toBe('async')
        expect((mountWebhookTrigger({...hmac, response_mode: 'sync'}).find('[data-testid=webhook-response-mode]').element as HTMLSelectElement).value).toBe('sync')
    })

    it('reads the webhook texts from the builder translations', () => {
        const text = mountWebhookTrigger(hmac).text()

        expect(text).toContain('Get URL')
        expect(text).toContain('Accept at once (202)')
    })

    it('switches to waiting for the flow\'s answer without touching the verification', async () => {
        const wrapper = mountWebhookTrigger({...hmac, response_mode: 'async'})

        await wrapper.find('[data-testid=webhook-response-mode]').setValue('sync')

        const emitted = wrapper.emitted('update:trigger')
        expect(emitted).toBeTruthy()
        expect((emitted?.at(-1)?.[0] as {config: Record<string, unknown>}).config).toEqual({...hmac, response_mode: 'sync'})
    })
})

describe('TriggerConfig automation permission', () => {
    beforeEach(() => grant([]))

    it('keeps a message trigger fully editable but offers no automatic types', () => {
        const wrapper = mount(TriggerConfig, {
            props: {trigger: {type: 'message', is_active: true, priority: 0, config: {keywords: [], phrases: []}}},
        })

        expect(wrapper.get('[data-testid="automation-lock"]').attributes('inert')).toBeUndefined()
        expect(wrapper.find('[data-testid="automation-lock-hint"]').exists()).toBe(false)

        const disabled = wrapper.findAll('select option').filter((o) => o.attributes('disabled') !== undefined).map((o) => o.text())
        expect(disabled).toEqual(['Schedule', 'Webhook', 'API', 'Event'])
    })

    it('shows an existing automatic trigger read-only with a hint', () => {
        const wrapper = mountEventTrigger('orders.paid')

        expect(wrapper.get('[data-testid="automation-lock"]').attributes('inert')).toBeDefined()
        expect(wrapper.get('[data-testid="automation-lock-hint"]').text()).toContain('permission_hints.automations')
    })

    it('changes nothing with the permission', () => {
        grant(['manage_automations'])
        const wrapper = mountEventTrigger('orders.paid')

        expect(wrapper.get('[data-testid="automation-lock"]').attributes('inert')).toBeUndefined()
        expect(wrapper.findAll('select option').some((o) => o.attributes('disabled') !== undefined)).toBe(false)
    })
})

describe('TriggerConfig run state', () => {
    const paused: TriggerStateInfo = {
        paused_at:           '2026-11-20T08:30:00+00:00',
        paused_reason:       'rate_breaker',
        refused_count:       3,
        last_refused_at:     '2026-11-20T08:31:00+00:00',
        last_refusal_reason: 'trigger_paused',
    }

    function mountScheduleTrigger(states: Record<string, TriggerStateInfo>) {
        return mount(TriggerConfig, {
            props: {
                trigger:       {id: 'T-1', type: 'schedule', is_active: true, priority: 0, config: {cron: '0 9 * * *', timezone: 'UTC'}},
                flowId:        'flow-1',
                triggerStates: states,
            },
        })
    }

    it('shows a paused trigger and resumes it for someone who manages automations', async () => {
        grant(['manage_automations'])
        const wrapper = mountScheduleTrigger({'t-1': paused})

        expect(wrapper.find('[data-testid=trigger-state]').exists()).toBe(true)
        await wrapper.find('[data-testid=trigger-resume]').trigger('click')
        await flushPromises()

        expect(resumeTrigger).toHaveBeenCalledWith('flow-1', 'T-1')
        expect(wrapper.emitted('trigger-states')).toHaveLength(1)
    })

    it('keeps the resume button off without the right to manage automations', () => {
        grant([])
        const wrapper = mountScheduleTrigger({'t-1': paused})

        expect(wrapper.find('[data-testid=trigger-resume]').attributes('disabled')).toBeDefined()
    })

    it('shows nothing for a trigger with no state', () => {
        grant(['manage_automations'])

        expect(mountScheduleTrigger({}).find('[data-testid=trigger-state]').exists()).toBe(false)
    })
})
