// @vitest-environment happy-dom
import {beforeEach, describe, expect, it, vi} from 'vitest'
import {mount} from '@vue/test-utils'
import {createPinia, setActivePinia} from 'pinia'

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({props: {translations: {builder: {}}}}),
}))

import AuthRequestConfig from './AuthRequestConfig.vue'

// The limits as AuthRequestV2NodeHandler::configSchema() sends them; deliberately not the handler's real numbers.
const schema = {
    code_length:             {type: 'number', min: 3, max: 9, default: 7},
    ttl_minutes:             {type: 'number', min: 2, max: 20, default: 4},
    max_attempts:            {type: 'number', min: 1, max: 12},
    max_sends:               {type: 'number', min: 1, max: 4, default: 2},
    resend_cooldown_seconds: {type: 'number', min: 15, max: 300, default: 45},
}

function mountConfig(config: Record<string, unknown>, nodeSchema: Record<string, unknown> = schema) {
    return mount(AuthRequestConfig, {
        props: {node: {id: 'n1', version: 2, config}, schema: nodeSchema},
        global: {stubs: {ConditionOperandPicker: true}},
    })
}

describe('AuthRequestConfig limits', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
    })

    it('takes the range and the default of each code limit from the config schema', () => {
        const input = mountConfig({method: 'sms_code'}).get('[data-testid="limit-code_length"]')

        expect(input.attributes('min')).toBe('3')
        expect(input.attributes('max')).toBe('9')
        expect(input.attributes('placeholder')).toBe('7')
    })

    it('shows no placeholder for a limit whose default the schema leaves out', () => {
        const input = mountConfig({method: 'email_code'}).get('[data-testid="limit-max_attempts"]')

        expect(input.attributes('max')).toBe('12')
        expect(input.attributes('placeholder')).toBe('')
    })

    it('takes the phone method\'s attempt range from the schema too', () => {
        const input = mountConfig({method: 'phone'}).get('[data-testid="phone-max-attempts"]')

        expect(input.attributes('min')).toBe('1')
        expect(input.attributes('max')).toBe('12')
    })

    it('leaves the range open while the schema is not known', () => {
        const input = mountConfig({method: 'sms_code'}, {}).get('[data-testid="limit-ttl_minutes"]')

        expect(input.attributes('min')).toBeUndefined()
        expect(input.attributes('max')).toBeUndefined()
    })
})
