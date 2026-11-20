import {describe, expect, it, vi} from 'vitest'

const {page} = vi.hoisted(() => ({page: {props: {} as Record<string, unknown>}}))

vi.mock('@inertiajs/vue3', () => ({usePage: () => page}))

import {usePermissions} from './usePermissions'

describe('usePermissions', () => {
    it('reads the list the server shares under auth.permissions', () => {
        page.props = {auth: {user: null, permissions: ['manage_automations']}}

        const {can} = usePermissions()

        expect(can('manage_automations')).toBe(true)
        expect(can('use_credentials')).toBe(false)
    })

    it('grants nothing without the shared list', () => {
        page.props = {permissions: ['manage_automations']}

        expect(usePermissions().can('manage_automations')).toBe(false)
    })
})
