#!/usr/bin/env node
/**
 * Local package development linker for npm, the counterpart of tools/dev-link-packages.php.
 *
 * The FaPost front-end packages (today `@fapost/ui`) are published to npm and installed by Core as ordinary
 * dependencies, so package.json and package-lock.json stay production-safe: CI and the Docker `assets` stage get the
 * registry copy, and no local path is ever written to them.
 *
 * For local development a package's sources live inside `packages/` (its own git checkout, ignored by the Core
 * repository). This script replaces the installed copy under `node_modules/<name>` with a relative symlink to that
 * checkout, so an edit in the package shows up in Vite at once, without a release.
 *
 * Packages are discovered: every `packages/<dir>/package.json` whose `name` is a dependency or devDependency of Core
 * is a candidate. It is a no-op without `packages/` (production, CI, the Docker `assets` stage), so it runs from
 * `postinstall` unconditionally: without it every `npm install` / `npm ci` would silently bring the registry copy
 * back.
 *
 * A candidate is linked only when Core accepts the checkout's version: the `version` of its package.json must satisfy
 * the constraint Core declares for it (exact versions, `^`, `~`, comparators, `*`/`x` and `||` are understood; a
 * pre-release is lower than its release). A `link:`, `file:` or `workspace:` spec always passes, like `@dev` in the
 * Composer linker. A checkout of another major version, linked silently, breaks every contract Core relies on, so a
 * refused one is skipped with a line naming both versions; a link this script made earlier for it is removed and
 * `npm ci` restores the registry copy. A constraint the script cannot parse skips the package, never links it.
 *
 * Environment:
 *   DEV_LINK_ROOT  overrides the project root (tests).
 *
 * Trap: with a package linked, `npm install` in Core treats the link target as part of its tree and prunes the
 * checkout's own node_modules. Use `npm ci` in Core; after an `npm install`, run `npm ci` in the checkout again. The
 * script warns when a linked checkout has no installed Vue, the first thing that goes missing.
 */
import {existsSync, lstatSync, mkdirSync, readFileSync, readdirSync, readlinkSync, rmSync, symlinkSync} from 'node:fs'
import {dirname, join, relative, resolve, sep} from 'node:path'
import {fileURLToPath} from 'node:url'

const root = process.env.DEV_LINK_ROOT ? resolve(process.env.DEV_LINK_ROOT) : resolve(dirname(fileURLToPath(import.meta.url)), '..')
const packagesDir = join(root, 'packages')

/** Reads a JSON file, or returns null when it is missing or unreadable (a dangling symlink included). */
function readJson(file) {
    try {
        return JSON.parse(readFileSync(file, 'utf8'))
    } catch {
        return null
    }
}

function isSymlink(path) {
    try {
        return lstatSync(path).isSymbolicLink()
    } catch {
        return false
    }
}

const PARTIAL = /^v?(\d+|[xX*])(?:\.(\d+|[xX*]))?(?:\.(\d+|[xX*]))?(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/
const VERSION = /^v?(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/

/** Parses a full `x.y.z[-pre]` version, or returns null. */
function parseVersion(text) {
    const match = VERSION.exec(String(text).trim())

    return match ? {major: +match[1], minor: +match[2], patch: +match[3], pre: match[4] ? match[4].split('.') : []} : null
}

/** Compares pre-release identifiers: numeric ones numerically and below alphanumeric ones, fewer fields below more. */
function comparePre(a, b) {
    if (a.length === 0 || b.length === 0) {
        // A pre-release is lower than its release.
        return a.length === b.length ? 0 : (a.length === 0 ? 1 : -1)
    }

    for (let i = 0; i < Math.min(a.length, b.length); i++) {
        const numA = /^\d+$/.test(a[i])
        const numB = /^\d+$/.test(b[i])
        const diff = numA && numB ? Number(a[i]) - Number(b[i]) : (numA ? -1 : numB ? 1 : (a[i] < b[i] ? -1 : a[i] > b[i] ? 1 : 0))

        if (diff !== 0) {
            return Math.sign(diff)
        }
    }

    return Math.sign(a.length - b.length)
}

function compareVersions(a, b) {
    return Math.sign(a.major - b.major) || Math.sign(a.minor - b.minor) || Math.sign(a.patch - b.patch) || comparePre(a.pre, b.pre)
}

/** The lowest possible version of a core: `-0` is the lowest pre-release, so `<2.0.0-0` excludes 2.0.0-beta too. */
function floor(major, minor, patch) {
    return {major, minor, patch, pre: ['0']}
}

/**
 * Turns one comparator (`^1.2`, `~1.2.3`, `>=1.0.0`, `1.x`, `1.2.3`, ...) into [operator, version] bounds that must all
 * hold, or returns null when it cannot be understood.
 */
function comparatorBounds(operator, text) {
    const match = PARTIAL.exec(text)

    if (!match) {
        return null
    }

    const [major, minor, patch] = match.slice(1, 4).map(part => (part === undefined || /^[xX*]$/.test(part)) ? null : Number(part))
    const pre = match[4] ? match[4].split('.') : []
    const low = {major: major ?? 0, minor: minor ?? 0, patch: patch ?? 0, pre}

    if (major === null) {
        // `*`, `x`: anything.
        return ['', '=', '>=', '<='].includes(operator) ? [] : null
    }

    // The first version past everything the partial version covers: 1 -> 2.0.0, 1.2 -> 1.3.0, 1.2.3 -> 1.2.4.
    const next = minor === null ? floor(major + 1, 0, 0) : (patch === null ? floor(major, minor + 1, 0) : floor(major, minor, patch + 1))

    switch (operator) {
        case '^': {
            const upper = major > 0 || minor === null
                ? floor(major + 1, 0, 0)
                : (minor > 0 || patch === null ? floor(0, minor + 1, 0) : floor(0, 0, patch + 1))

            return [['>=', low], ['<', upper]]
        }
        case '~':
            return [['>=', low], ['<', minor === null ? floor(major + 1, 0, 0) : floor(major, minor + 1, 0)]]
        case '>=':
            return [['>=', low]]
        case '>':
            return patch === null ? [['>=', next]] : [['>', low]]
        case '<':
            return [['<', low]]
        case '<=':
            return patch === null ? [['<', next]] : [['<=', low]]
        case '':
        case '=':
            return patch === null ? [['>=', low], ['<', next]] : [['>=', low], ['<=', low]]
        default:
            return null
    }
}

/**
 * Whether `version` satisfies the npm range `constraint`. Returns null for a constraint this script does not
 * understand (tags, URLs, hyphen ranges), so the caller can skip the package instead of guessing.
 */
function satisfies(version, constraint) {
    const subject = parseVersion(version)
    let accepted = false

    if (subject === null) {
        return false
    }

    for (const alternative of constraint.split('||')) {
        const bounds = []

        for (const [, operator, operand] of alternative.trim().matchAll(/(\^|~>?|>=|<=|>|<|=)?\s*(\S+)/g)) {
            const part = comparatorBounds(operator === '~>' ? '~' : (operator ?? ''), operand)

            if (part === null) {
                return null
            }

            bounds.push(...part)
        }

        // An empty alternative has no bounds and means any version, like `*`.
        accepted ||= bounds.every(([operator, bound]) => {
            const order = compareVersions(subject, bound)

            return operator === '>=' ? order >= 0 : operator === '>' ? order > 0 : operator === '<' ? order < 0 : order <= 0
        })
    }

    return accepted
}

if (!existsSync(packagesDir)) {
    console.log('[dev:link] no packages/ directory — using registry copies')
    process.exit(0)
}

const manifest = readJson(join(root, 'package.json')) ?? {}
const constraints = {...(manifest.devDependencies ?? {}), ...(manifest.dependencies ?? {})}
let linked = 0

for (const entry of readdirSync(packagesDir)) {
    const checkout = readJson(join(packagesDir, entry, 'package.json'))
    const name = checkout?.name

    if (typeof name !== 'string' || !Object.hasOwn(constraints, name)) {
        continue
    }

    const installed = join(root, 'node_modules', name)
    const constraint = String(constraints[name])
    const version = typeof checkout.version === 'string' ? checkout.version : null
    const verdict = /^(link|file|workspace):/.test(constraint) ? true : (version === null ? false : satisfies(version, constraint))

    if (verdict !== true) {
        const reason = verdict === null
            ? `Core's constraint ${constraint} is not one this script understands`
            : `the checkout is at ${version ?? 'no version'}, which does not satisfy ${constraint}`

        console.log(`[dev:link] skipped node_modules/${name}: ${reason}`)

        // Only a link this script made (into packages/) is removed; a registry copy is left alone.
        if (isSymlink(installed)) {
            const previous = readlinkSync(installed)

            if (resolve(dirname(installed), previous).startsWith(packagesDir + sep)) {
                rmSync(installed, {force: true})
                console.log(`[dev:link] removed node_modules/${name} -> ${previous}; run \`npm ci\` to restore the registry copy`)
            }
        }

        continue
    }

    // Relative target, so the link resolves on the host and inside a container alike.
    const target = relative(dirname(installed), join(packagesDir, entry))

    if (!existsSync(join(packagesDir, entry, 'node_modules', 'vue'))) {
        console.warn(`[dev:link] packages/${entry} has no node_modules/vue: run \`npm ci\` in packages/${entry} (an \`npm install\` in Core prunes a linked checkout; use \`npm ci\` here)`)
    }

    if (isSymlink(installed) && readlinkSync(installed) === target) {
        linked++
        continue
    }

    // Remove whatever npm placed there (a real directory, a stale link, ...).
    rmSync(installed, {recursive: true, force: true})
    mkdirSync(dirname(installed), {recursive: true})
    symlinkSync(target, installed, 'dir')
    linked++
    console.log(`[dev:link] node_modules/${name} -> ${target}`)
}

if (linked === 0) {
    console.log('[dev:link] no local package sources found — using registry copies')
}
