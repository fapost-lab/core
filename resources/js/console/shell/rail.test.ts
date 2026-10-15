import {describe, expect, it} from 'vitest'
import {filterApplications, move, railApplications, togglePin} from './rail'
import type {ShellApplication, ShellApplications} from './types'

const app = (key: string, label = key, short = label): ShellApplication => ({
    key,
    label,
    short,
    scope: '',
    icon: 'circle',
    href: `/${key}`,
    external: false,
    perAssistant: key === 'console',
})

const data = (rail: string[], current: string | null): ShellApplications => ({
    current,
    items: [app('console', 'Console'), app('admin', 'Admin panel', 'Admin'), app('feedback', 'Feedback', 'Reviews')],
    rail,
    railUrl: '/console/applications/rail',
})

describe('railApplications', () => {
    it('keeps the pinned order and adds the current application at the end when it is not pinned', () => {
        expect(railApplications(data(['admin', 'console'], 'admin')).map((item) => item.key)).toEqual(['admin', 'console'])
        expect(railApplications(data(['admin'], 'feedback')).map((item) => item.key)).toEqual(['admin', 'feedback'])
        expect(railApplications(data([], null))).toEqual([])
    })

    it('skips a pinned key the user may not open', () => {
        expect(railApplications(data(['gone', 'admin'], 'admin')).map((item) => item.key)).toEqual(['admin'])
    })
})

describe('filterApplications', () => {
    const items = data([], null).items

    it('matches the name or the caption, case-insensitively', () => {
        expect(filterApplications(items, ' ADMIN ').map((item) => item.key)).toEqual(['admin'])
        expect(filterApplications(items, 'revi').map((item) => item.key)).toEqual(['feedback'])
        expect(filterApplications(items, '')).toHaveLength(3)
        expect(filterApplications(items, 'zzz')).toEqual([])
    })
})

describe('togglePin', () => {
    it('pins at the end and unpins in place', () => {
        expect(togglePin(['console'], 'admin')).toEqual(['console', 'admin'])
        expect(togglePin(['console', 'admin', 'feedback'], 'admin')).toEqual(['console', 'feedback'])
    })
})

describe('move', () => {
    it('moves one place and stays put at the ends', () => {
        expect(move(['a', 'b', 'c'], 'b', -1)).toEqual(['b', 'a', 'c'])
        expect(move(['a', 'b', 'c'], 'b', 1)).toEqual(['a', 'c', 'b'])
        expect(move(['a', 'b'], 'a', -1)).toEqual(['a', 'b'])
        expect(move(['a', 'b'], 'b', 1)).toEqual(['a', 'b'])
        expect(move(['a'], 'x', 1)).toEqual(['a'])
    })
})
