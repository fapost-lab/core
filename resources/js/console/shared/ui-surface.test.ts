import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * Core's copy of the kit's surface (`ui-surface.json`, read by PHP at run time where there is no node_modules) is the
 * installed `@fapost/ui`'s own `surface.json`, and the ui entry re-exports exactly it. That every subpath is a module
 * of the kit and that no name is exported by two of them is the package's test (`src/surface.test.ts` in fapost-lab/ui);
 * here a kit bump that changes the surface fails until Core's copy follows.
 */
const here = dirname(fileURLToPath(import.meta.url))
const readSubpaths = (file: string): string[] => (JSON.parse(readFileSync(file, 'utf8')) as { subpaths: string[] }).subpaths
const subpaths = readSubpaths(resolve(here, 'ui-surface.json'))

describe('the public surface of @fapost/ui', () => {
  it('is the surface of the installed package', () => {
    const installed = createRequire(import.meta.url).resolve('@fapost/ui/surface.json')

    expect(subpaths).toEqual(readSubpaths(installed))
  })

  it('is re-exported by the ui entry one subpath per line, in the listed order', () => {
    const reExports = [...readFileSync(resolve(here, 'ui.ts'), 'utf8').matchAll(/^export \* from '@fapost\/ui\/(.+)'$/gm)].map((match) => match[1])

    expect(reExports).toEqual(subpaths)
  })
})
