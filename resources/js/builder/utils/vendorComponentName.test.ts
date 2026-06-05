import {describe, expect, it} from 'vitest'
import {pascalToSnake, vendorComponentType} from './vendorComponentName'

describe('pascalToSnake', () => {
    it('lower-cases a single word', () => {
        expect(pascalToSnake('Demo')).toBe('demo')
    })

    it('splits multi-word PascalCase with underscores', () => {
        expect(pascalToSnake('SyncEmployee')).toBe('sync_employee')
        expect(pascalToSnake('DemoNode')).toBe('demo_node')
    })

    it('treats each capital as a boundary', () => {
        expect(pascalToSnake('CreateDealNow')).toBe('create_deal_now')
    })
})

describe('vendorComponentType', () => {
    it('maps a Config file path to its snake_case node type', () => {
        const path = '../../../../vendor/fapost/solution-hr/resources/js/builder/SyncEmployeeConfig.vue'
        expect(vendorComponentType(path, 'Config')).toBe('sync_employee')
    })

    it('maps a Preview file path to its snake_case node type', () => {
        const path = '/abs/vendor/fapost/solution-demo/resources/js/builder/DemoNodePreview.vue'
        expect(vendorComponentType(path, 'Preview')).toBe('demo_node')
    })

    it('returns null when the suffix does not match', () => {
        const path = 'vendor/fapost/solution-hr/resources/js/builder/SyncEmployeePreview.vue'
        expect(vendorComponentType(path, 'Config')).toBeNull()
    })

    it('returns null when stripping the suffix leaves an empty name', () => {
        expect(vendorComponentType('vendor/x/Config.vue', 'Config')).toBeNull()
    })

    it('ignores non-vue or malformed names gracefully', () => {
        expect(vendorComponentType('vendor/x/notes.txt', 'Config')).toBeNull()
    })
})
