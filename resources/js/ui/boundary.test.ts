/// <reference types="node" />
import {existsSync, readdirSync, readFileSync, statSync} from 'node:fs'
import {dirname, join, relative, resolve, sep} from 'node:path'
import {fileURLToPath} from 'node:url'
import ts from 'typescript'
import {describe, expect, it} from 'vitest'

/**
 * The kit's boundary: it is the package `@fapost/ui`, so it may import only itself, through a public subpath or a
 * relative path that stays inside it, and the packages below. It imports nothing from the application around it
 * (pages, shell, console, builder, translations), reads no Inertia page props, and keeps CSS in styles/.
 */
const kitRoot = dirname(fileURLToPath(import.meta.url))

/** Packages the kit may import, by package name. A subpath of one (`vue-sonner/style.css`) counts as the package. */
const allowedPackages = new Set([
    'vue',
    '@inertiajs/vue3',
    'reka-ui',
    'vue-sonner',
    '@vueuse/core',
    '@lucide/vue',
    'class-variance-authority',
    'clsx',
    'tailwind-merge',
])

/** What only the kit's tests may import on top of that. */
const allowedInTests = new Set(['vitest', '@vue/test-utils', 'typescript'])

/** Names the kit may take from Inertia: the router, for server-driven components. Never `usePage` and page props. */
const allowedFromInertia = new Set(['router'])

const libModules = new Set(['utils', 'relative-time', 'messages'])

function filesUnder(directory: string): string[] {
    return readdirSync(directory).flatMap((name) => {
        const path = join(directory, name)

        return statSync(path).isDirectory() ? filesUnder(path) : [path]
    })
}

function packageName(specifier: string): string {
    const parts = specifier.split('/')

    return specifier.startsWith('@') ? parts.slice(0, 2).join('/') : parts[0]
}

/** Whether `@fapost/ui/<subpath>` is a public subpath: a component's index or a module of lib/, never a file inside. */
function isPublicSubpath(subpath: string): boolean {
    const component = /^components\/([\w-]+)$/.exec(subpath)

    if (component) {
        return existsSync(join(kitRoot, 'components', component[1], 'index.ts'))
    }

    const lib = /^lib\/([\w-]+)$/.exec(subpath)

    return lib !== null && libModules.has(lib[1])
}

/** One import, re-export or dynamic import: its specifier (`null` when computed) and the names it takes. */
interface ModuleReference {
    specifier: string | null
    /** The imported names by their exported name; `*` for a default, namespace, re-export or dynamic import. */
    names: string[]
}

/** The scripts of a file: the whole of a .ts file, every <script> block of a .vue file. */
function scriptsOf(file: string, text: string): string[] {
    return file.endsWith('.vue') ? [...text.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)].map((match) => match[1]) : [text]
}

/** Every module a script imports or re-exports, statically or with `import()`, read from its syntax tree. */
function referencesOf(script: string): ModuleReference[] {
    const source = ts.createSourceFile('script.ts', script, ts.ScriptTarget.Latest, true)
    const found: ModuleReference[] = []

    const literal = (node: ts.Node | undefined): string | null =>
        node !== undefined && (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) ? node.text : null

    const visit = (node: ts.Node): void => {
        if (ts.isImportDeclaration(node)) {
            const clause = node.importClause
            const names: string[] = []

            if (clause?.name !== undefined) {
                names.push('*')
            }

            if (clause?.namedBindings !== undefined) {
                if (ts.isNamespaceImport(clause.namedBindings)) {
                    names.push('*')
                } else {
                    names.push(...clause.namedBindings.elements.map((element) => (element.propertyName ?? element.name).text))
                }
            }

            found.push({specifier: literal(node.moduleSpecifier), names})
        } else if (ts.isExportDeclaration(node) && node.moduleSpecifier !== undefined) {
            found.push({specifier: literal(node.moduleSpecifier), names: ['*']})
        } else if (ts.isCallExpression(node) && node.expression.kind === ts.SyntaxKind.ImportKeyword) {
            found.push({specifier: literal(node.arguments[0]), names: ['*']})
        }

        ts.forEachChild(node, visit)
    }

    visit(source)

    return found
}

/** Why a module `file` refers to crosses the kit's boundary; `null` when it does not. */
function violation(file: string, {specifier, names}: ModuleReference): string | null {
    if (specifier === null) {
        return 'is computed, so its target cannot be checked'
    }

    const isTest = file.endsWith('.test.ts')

    if (specifier.startsWith('.')) {
        const target = resolve(dirname(file), specifier)

        return target === kitRoot || target.startsWith(kitRoot + sep) ? null : 'leaves the kit'
    }

    if (specifier.startsWith('@fapost/ui/')) {
        return isPublicSubpath(specifier.slice('@fapost/ui/'.length)) ? null : 'is not a public subpath of the kit'
    }

    if (isTest && (specifier.startsWith('node:') || allowedInTests.has(packageName(specifier)))) {
        return null
    }

    if (!allowedPackages.has(packageName(specifier))) {
        return 'is not a package the kit may import'
    }

    if (packageName(specifier) === '@inertiajs/vue3' && names.some((name) => !allowedFromInertia.has(name))) {
        return 'takes more than the router from Inertia'
    }

    return null
}

/** Every boundary violation in a file, as `<file>: '<specifier>' <reason>`. */
function violationsIn(file: string, text: string): string[] {
    return scriptsOf(file, text)
        .flatMap(referencesOf)
        .map((reference) => ({reference, reason: violation(file, reference)}))
        .filter(({reason}) => reason !== null)
        .map(({reference, reason}) => `${relative(kitRoot, file)}: '${reference.specifier ?? '(computed)'}' ${reason}`)
}

const sources = filesUnder(kitRoot).filter((file) => /\.(ts|vue)$/.test(file))

describe('the kit boundary', () => {
    it('imports only itself, through public subpaths, and the packages it may use', () => {
        expect(sources.flatMap((file) => violationsIn(file, readFileSync(file, 'utf8')))).toEqual([])
    })

    it('keeps components free of <style> blocks', () => {
        const styled = sources.filter((file) => file.endsWith('.vue') && /<style[\s>]/.test(readFileSync(file, 'utf8')))

        expect(styled.map((file) => relative(kitRoot, file))).toEqual([])
    })

    it('keeps its CSS in styles/, importing only its own files and the fonts', () => {
        const stylesheets = filesUnder(kitRoot).filter((file) => file.endsWith('.css'))
        const outside = stylesheets.filter((file) => dirname(file) !== join(kitRoot, 'styles'))
        const imports = stylesheets.flatMap((file) =>
            [...readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/@import\s+['"]([^'"]+)['"]/g)]
                .map((match) => match[1])
                .filter((specifier) => !/^\.\/[\w-]+\.css$/.test(specifier) && !specifier.startsWith('@fontsource-variable/'))
                .map((specifier) => `${relative(kitRoot, file)}: '${specifier}'`),
        )

        expect(outside.map((file) => relative(kitRoot, file))).toEqual([])
        expect(imports).toEqual([])
    })

    it('recognises what crosses the boundary', () => {
        const page = join(kitRoot, 'components', 'button', 'Button.vue')
        const crossings = (script: string): number => violationsIn(page, `<script setup lang="ts">\n${script}\n</script>`).length

        expect(crossings("import { AppShell } from '@console/shell'")).toBe(1)
        expect(crossings("import { AppShell } from '../../../console/shell'")).toBe(1)
        expect(crossings("import type { CellTone } from '@fapost/ui/components/data-table/cells'")).toBe(1)
        expect(crossings("import { router, usePage } from '@inertiajs/vue3'")).toBe(1)
        expect(crossings("import { router as go, usePage as page } from '@inertiajs/vue3'")).toBe(1)
        expect(crossings("import Inertia, { router } from '@inertiajs/vue3'")).toBe(1)
        expect(crossings("import * as Inertia from '@inertiajs/vue3'")).toBe(1)
        expect(crossings("export { usePage } from '@inertiajs/vue3'")).toBe(1)
        expect(crossings("const a = 1; import { AppShell } from '@console/shell'")).toBe(1)
        expect(crossings("const shell = () => import(`@console/shell`)")).toBe(1)
        expect(crossings("const name = 'shell'\nconst load = () => import(`@console/${name}`)")).toBe(1)
        expect(crossings("import { it } from 'vitest'")).toBe(1)
        expect(crossings("import 'vue-sonner/style.css'")).toBe(0)
        expect(crossings("import { DataTable, ValueCell } from '@fapost/ui/components/data-table'")).toBe(0)
        expect(crossings("import { router } from '@inertiajs/vue3'")).toBe(0)
        expect(crossings("import Button from './Button.vue'")).toBe(0)
    })
})
