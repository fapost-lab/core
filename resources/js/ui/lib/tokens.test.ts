/// <reference types="node" />
import {readFileSync} from 'node:fs'
import {describe, expect, it} from 'vitest'

const css = readFileSync(new URL('../../../css/tokens.css', import.meta.url), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')

/** Custom properties declared inside the first block opened by `selector`. */
function declared(selector: string): string[] {
    const start = css.indexOf(`${selector} {`)
    expect(start, `${selector} block`).toBeGreaterThanOrEqual(0)
    const end = css.indexOf('}', start)
    return [...css.slice(start, end).matchAll(/(--[\w-]+)\s*:/g)].map((m) => m[1])
}

const themeIndependent = new Set(['--radius', '--font-sans', '--font-display', '--font-mono'])
const themed = (names: string[]) => names.filter((n) => !themeIndependent.has(n)).sort()

describe('tokens.css', () => {
    const light = declared(':root')
    const dark = declared('.dark')

    it('declares every themed :root property in .dark', () => {
        expect(themed(dark)).toEqual(themed(light))
    })

    it('keeps theme-independent properties out of .dark', () => {
        expect(dark.filter((n) => themeIndependent.has(n))).toEqual([])
    })

    it('has no duplicate declarations', () => {
        expect(new Set(light).size).toBe(light.length)
        expect(new Set(dark).size).toBe(dark.length)
    })
})
