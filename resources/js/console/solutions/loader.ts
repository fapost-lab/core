import type { Component } from 'vue'
import { findSolutionModule, parseSolutionBundleMap, type SolutionBundleMap, type SolutionModuleKind } from './map'

/** How long a Solution module, its stylesheets included, may take before it counts as unavailable. */
export const SOLUTION_LOAD_TIMEOUT_MS = 10_000

/** A Solution page or component that could not be loaded; the reason is in `message` and `cause`. */
export class SolutionUnavailableError extends Error {
  constructor(
    readonly componentName: string,
    message: string,
    options?: { cause?: unknown },
  ) {
    super(`${componentName}: ${message}`, options)
    this.name = 'SolutionUnavailableError'
  }
}

/** Rejects when `promise` has not settled within `ms`. */
export function withTimeout<T>(promise: Promise<T>, ms: number, what: string): Promise<T> {
  return new Promise<T>((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error(`${what} did not load within ${ms} ms`)), ms)

    promise.then(
      (value) => {
        clearTimeout(timer)
        resolve(value)
      },
      (error: unknown) => {
        clearTimeout(timer)
        reject(error)
      },
    )
  })
}

/** Whether a module's default export can be mounted as a component. */
export function isComponent(value: unknown): value is Component {
  return (typeof value === 'object' && value !== null) || typeof value === 'function'
}

let bundleMap: SolutionBundleMap | null = null

/**
 * The map in `#fapost-solutions`, read once: it changes only with activation or an update, and those change the
 * Inertia version, which reloads the whole page.
 */
function currentBundleMap(): SolutionBundleMap {
  bundleMap ??= parseSolutionBundleMap(document.getElementById('fapost-solutions')?.textContent)

  return bundleMap
}

const stylesheets = new Map<string, Promise<void>>()

/** Attaches a stylesheet once per URL and resolves when it has loaded; a failed one may be tried again later. */
export function ensureStylesheet(href: string): Promise<void> {
  const known = stylesheets.get(href)

  if (known !== undefined) {
    return known
  }

  const loading = new Promise<void>((resolve, reject) => {
    const link = document.createElement('link')
    link.rel = 'stylesheet'
    link.href = href
    link.onload = () => resolve()
    link.onerror = () => {
      stylesheets.delete(href)
      link.remove()
      reject(new Error(`stylesheet ${href} failed to load`))
    }
    document.head.appendChild(link)
  })

  stylesheets.set(href, loading)

  return loading
}

async function importModule(url: string, css: string[]): Promise<unknown> {
  await Promise.all(css.map((href) => ensureStylesheet(href)))

  return ((await import(/* @vite-ignore */ url)) as { default?: unknown }).default
}

async function loadSolutionModule(name: string, kind: SolutionModuleKind): Promise<Component> {
  const ref = findSolutionModule(currentBundleMap(), name, kind)

  if (ref === null) {
    throw new SolutionUnavailableError(name, 'not in the map of active Solutions')
  }

  let component: unknown

  try {
    component = await withTimeout(importModule(ref.url, ref.css), SOLUTION_LOAD_TIMEOUT_MS, ref.url)
  } catch (error) {
    throw new SolutionUnavailableError(name, 'failed to load', { cause: error })
  }

  if (!isComponent(component)) {
    throw new SolutionUnavailableError(name, 'the module has no component as its default export')
  }

  return component
}

/**
 * Loads a component a Solution declared for a point of Core's screens (`<id>::<Path>` from the `components` map),
 * stylesheets first. Rejects with {@link SolutionUnavailableError}; the caller hides the element.
 */
export function loadSolutionComponent(name: string): Promise<Component> {
  return loadSolutionModule(name, 'components')
}

/**
 * Resolves an Inertia page named `<id>::<Path>`. A page without its own `layout` gets the console's shell; a page
 * that cannot be loaded becomes `unavailable`, rendered inside the shell, instead of a blank screen.
 */
export async function resolveSolutionPage(name: string, fallback: { layout: Component; unavailable: Component }): Promise<Component> {
  try {
    const page = (await loadSolutionModule(name, 'pages')) as Component & { layout?: unknown }

    if (page.layout === undefined) {
      page.layout = fallback.layout
    }

    return page
  } catch (error) {
    console.error(error)

    return fallback.unavailable
  }
}
