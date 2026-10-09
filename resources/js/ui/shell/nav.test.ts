import {describe, expect, it} from 'vitest'
import {breadcrumbs, findActive, initials, interpolate, pathOf} from './nav'
import type {NavGroup} from './types'

const item = (key: string, href: string, label = key) => ({key, label, href, icon: 'home', external: false, badge: null})

const groups: NavGroup[] = [
    {label: null, items: [item('dashboard', '/admin', 'Dashboard')]},
    {label: 'Staff', items: [item('users', '/admin/users', 'Users'), item('roles', '/admin/roles', 'Roles')]},
]

describe('pathOf', () => {
    it('drops the query string and the fragment', () => {
        expect(pathOf('/admin/users?page=2#top')).toBe('/admin/users')
    })

    it('takes the path of an absolute URL', () => {
        expect(pathOf('https://acme.example/admin/users?x=1')).toBe('/admin/users')
    })
})

describe('findActive', () => {
    it('matches the exact page and its sub-pages', () => {
        expect(findActive(groups, '/admin/users')?.key).toBe('users')
        expect(findActive(groups, '/admin/users/01H/edit?tab=1')?.key).toBe('users')
    })

    it('prefers the longest match over a shorter parent', () => {
        expect(findActive(groups, '/admin/roles')?.key).toBe('roles')
        expect(findActive(groups, '/admin')?.key).toBe('dashboard')
    })

    it('finds nothing for a page outside the menu', () => {
        expect(findActive(groups, '/elsewhere')).toBeNull()
    })

    it('does not match on a shared prefix that is not a path segment', () => {
        expect(findActive(groups, '/admin/users-archive')?.key).toBe('dashboard')
    })
})

describe('breadcrumbs', () => {
    it('lists root, group and page', () => {
        expect(breadcrumbs('Administration', groups, '/admin/users')).toEqual(['Administration', 'Staff', 'Users'])
    })

    it('skips an unnamed group', () => {
        expect(breadcrumbs('Administration', groups, '/admin')).toEqual(['Administration', 'Dashboard'])
    })

    it('shows only the root outside the menu', () => {
        expect(breadcrumbs('Administration', groups, '/elsewhere')).toEqual(['Administration'])
    })

    it('does not repeat a group named like its only page', () => {
        const same: NavGroup[] = [{label: 'Media', items: [item('media', '/admin/media', 'Media')]}]

        expect(breadcrumbs('Administration', same, '/admin/media')).toEqual(['Administration', 'Media'])
    })
})

describe('interpolate', () => {
    it('fills the placeholders', () => {
        expect(interpolate('Live sessions (:count)', {count: 3})).toBe('Live sessions (3)')
        expect(interpolate('(:name, :email)', {name: 'Olga', email: 'o@x.io'})).toBe('(Olga, o@x.io)')
    })

    it('inserts a value verbatim', () => {
        expect(interpolate('Delete :name?', {name: "a$&b$'c"})).toBe("Delete a$&b$'c?")
    })
})

describe('initials', () => {
    it('takes up to two letters', () => {
        expect(initials('olga petrenko ivanivna')).toBe('OP')
        expect(initials('Olga')).toBe('O')
        expect(initials('   ')).toBe('?')
    })
})
