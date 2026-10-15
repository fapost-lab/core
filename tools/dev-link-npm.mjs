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
 * is linked. It is a no-op without `packages/` (production, CI, the Docker `assets` stage), so it runs from
 * `postinstall` unconditionally: without it every `npm install` / `npm ci` would silently bring the registry copy
 * back.
 *
 * Trap: with a package linked, `npm install` in Core treats the link target as part of its tree and prunes the
 * checkout's own node_modules. Use `npm ci` in Core; after an `npm install`, run `npm ci` in the checkout again. The
 * script warns when a linked checkout has no installed Vue, the first thing that goes missing.
 */
import {existsSync, lstatSync, mkdirSync, readFileSync, readdirSync, readlinkSync, rmSync, symlinkSync} from 'node:fs'
import {dirname, join, relative, resolve} from 'node:path'
import {fileURLToPath} from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
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

if (!existsSync(packagesDir)) {
    console.log('[dev:link] no packages/ directory — using registry copies')
    process.exit(0)
}

const manifest = readJson(join(root, 'package.json')) ?? {}
const wanted = new Set([...Object.keys(manifest.dependencies ?? {}), ...Object.keys(manifest.devDependencies ?? {})])
let linked = 0

for (const entry of readdirSync(packagesDir)) {
    const name = readJson(join(packagesDir, entry, 'package.json'))?.name

    if (typeof name !== 'string' || !wanted.has(name)) {
        continue
    }

    const installed = join(root, 'node_modules', name)

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
