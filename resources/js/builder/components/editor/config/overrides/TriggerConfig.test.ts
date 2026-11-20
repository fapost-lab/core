// @vitest-environment happy-dom
import {describe, expect, it} from 'vitest'
import {mount} from '@vue/test-utils'

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

    it('switches to waiting for the flow\'s answer without touching the verification', async () => {
        const wrapper = mountWebhookTrigger({...hmac, response_mode: 'async'})

        await wrapper.find('[data-testid=webhook-response-mode]').setValue('sync')

        const emitted = wrapper.emitted('update:trigger')
        expect(emitted).toBeTruthy()
        expect((emitted?.at(-1)?.[0] as {config: Record<string, unknown>}).config).toEqual({...hmac, response_mode: 'sync'})
    })
})
