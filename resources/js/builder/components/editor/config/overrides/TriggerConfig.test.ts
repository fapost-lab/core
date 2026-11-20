// @vitest-environment happy-dom
import {beforeEach, describe, expect, it, vi} from 'vitest'
import {mount} from '@vue/test-utils'


const {pageProps} = vi.hoisted(() => ({pageProps: {
    auth:         {permissions: [] as string[]},
    translations: {builder: {webhook_trigger: {get_url: 'Get URL', response_async: 'Accept at once (202)'}}},
}}))

vi.mock('@inertiajs/vue3', () => ({usePage: () => ({props: pageProps})}))

function grant(permissions: string[]): void {
    pageProps.auth.permissions = permissions
}

import TriggerConfig from './TriggerConfig.vue'

function mountEventTrigger(eventName: string) {
    return mount(TriggerConfig, {
        props: {
            trigger:         {type: 'event', is_active: true, priority: 0, config: {event_name: eventName}},
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
