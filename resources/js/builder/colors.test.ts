/// <reference types="node" />
import {readdirSync, readFileSync, statSync} from 'node:fs'
import {join, relative} from 'node:path'
import {fileURLToPath} from 'node:url'
import {describe, expect, it} from 'vitest'

/**
 * The builder follows the console's light/dark theme, which only works while every colour is a variable defined in
 * builder.css (light in :root, dark in .dark). A literal colour anywhere else would stay the same in both themes.
 * The one place literals belong is a `--name: value;` definition inside the :root and .dark blocks of builder.css.
 */
const repoRoot = fileURLToPath(new URL('../../../', import.meta.url))
const builderDir = fileURLToPath(new URL('.', import.meta.url))
const builderCss = join(repoRoot, 'resources/css/builder.css')

const COLOUR_LITERAL = /#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(|(?<![\w-])(?:white|black)(?![\w-])/

function sourceFiles(dir: string): string[] {
    return readdirSync(dir).flatMap((name) => {
        const path = join(dir, name)

        if (statSync(path).isDirectory()) {
            return sourceFiles(path)
        }

        return (path.endsWith('.vue') || path.endsWith('.ts')) && !path.endsWith('.test.ts') ? [path] : []
    })
}

/** Block, HTML and line comments (a `//` after `:` is part of a URL, not a comment) and inline `data:` URLs. */
function withoutNoise(text: string): string {
    return text
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/(?<![:\w])\/\/[^\n]*/g, '')
        .replace(/url\(\s*(["']?)data:[\s\S]*?\1\s*\)/g, 'url()')
}

/** Removes `--name: value;` definitions, but only inside the top-level `:root { }` and `.dark { }` blocks. */
function withoutRootDefinitions(css: string): string {
    return css.replace(/(^|\n)(:root|\.dark)\s*\{([\s\S]*?)\n\}/g, (_all, lead: string, selector: string, body: string) =>
        `${lead}${selector} {${body.replace(/--[\w-]+\s*:[^;{}]*;/g, '')}\n}`,
    )
}

function literalLines(text: string): string[] {
    return text.split('\n').filter((line) => COLOUR_LITERAL.test(line)).map((line) => line.trim())
}

function cssOffenders(css: string): string[] {
    return literalLines(withoutRootDefinitions(withoutNoise(css)))
}

function sourceOffenders(source: string): string[] {
    return literalLines(withoutNoise(source))
}

describe('the guard itself', () => {
    it('allows a literal in a :root or .dark definition only', () => {
        expect(cssOffenders(':root {\n  --a: #fff;\n}\n.dark {\n  --a: rgba(0,0,0,.5);\n}')).toEqual([])
        expect(cssOffenders('.panel {\n  --a: #fff;\n}')).toEqual(['--a: #fff;'])
        expect(cssOffenders('.panel {\n  color: #fff;\n}')).toEqual(['color: #fff;'])
        expect(cssOffenders(':root {\n  --a: #fff;\n}\n.panel { color: rgb(1, 2, 3); }')).toHaveLength(1)
    })

    it('does not let a URL swallow the rest of the line', () => {
        expect(sourceOffenders('.a { background: url(http://x.test/a.png); color: #fff; }')).toHaveLength(1)
        expect(sourceOffenders('.a { color: red; } // #fff in a comment')).toEqual([])
        expect(sourceOffenders('.a { background: url("data:image/svg+xml,%3Csvg fill=\'%23a09b94\'/%3E"); }')).toEqual([])
    })

    it('sees inline styles in templates and colours in scripts', () => {
        expect(sourceOffenders('<span style="color: #888">x</span>')).toHaveLength(1)
        expect(sourceOffenders('<span :style="{ color: \'rgb(1, 2, 3)\' }">x</span>')).toHaveLength(1)
        expect(sourceOffenders("const c = { bg: '#f0edf8' }")).toHaveLength(1)
        expect(sourceOffenders('<!-- #fff --><span style="color: var(--text)">x</span>')).toEqual([])
    })
})

describe('builder colours', () => {
    it('keeps colour literals out of builder.css outside the :root and .dark definitions', () => {
        expect(cssOffenders(readFileSync(builderCss, 'utf8'))).toEqual([])
    })

    it('keeps colour literals out of every builder component and script', () => {
        const offenders = sourceFiles(builderDir).flatMap((file) =>
            sourceOffenders(readFileSync(file, 'utf8')).map((line) => `${relative(repoRoot, file)}: ${line}`),
        )

        expect(offenders).toEqual([])
    })

    it('defines a dark override for every colour that builder.css defines as a literal', () => {
        const css = readFileSync(builderCss, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')
        const block = (selector: string): string => {
            const start = css.indexOf(`${selector} {`)
            expect(start, `${selector} block`).toBeGreaterThanOrEqual(0)

            return css.slice(start, css.indexOf('\n}', start))
        }
        const literalColourVars = [...block(':root').matchAll(/(--[\w-]+)\s*:\s*(?:#[0-9a-fA-F]{3,8})\s*;/g)].map((m) => m[1])
        const darkVars = new Set([...block('.dark').matchAll(/(--[\w-]+)\s*:/g)].map((m) => m[1]))
        // Pure black shadows read the same in both themes; --radius is not a colour.
        const sameInBothThemes = new Set(['--shade', '--radius'])

        expect(literalColourVars.filter((name) => !darkVars.has(name) && !sameInBothThemes.has(name))).toEqual([])
    })
})
