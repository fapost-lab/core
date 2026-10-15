// @vitest-environment happy-dom
import { readdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { icons } from '@lucide/vue'
import { iconFor, loadIcon, lucideExportName } from './icons'
import ShellIcon from './ShellIcon.vue'

const here = dirname(fileURLToPath(import.meta.url))
const lucideNames = readdirSync(resolve(here, '../../../../node_modules/@lucide/vue/dist/esm/icons'))
  .filter((file) => file.endsWith('.mjs') && file !== 'index.mjs')
  .map((file) => file.slice(0, -'.mjs'.length))

describe('icons by name', () => {
  it('finds every lucide icon by its kebab-case name', () => {
    const catalogue = icons as unknown as Record<string, unknown>
    const missing = lucideNames.filter((name) => !Object.hasOwn(catalogue, lucideExportName(name)))

    expect(lucideNames.length).toBeGreaterThan(1000)
    expect(missing).toEqual([])
  })

  it('gives Core’s own icons without the catalogue and any other from it', async () => {
    expect(await loadIcon('users')).toBe(iconFor('users'))
    expect(await loadIcon('kanban')).toBe(icons.Kanban)
    expect(await loadIcon('lamp-desk')).toBe(icons.LampDesk)
  })

  it('has none for a name lucide does not know or a malformed one', async () => {
    expect(await loadIcon('no-such-icon-at-all')).toBeNull()
    expect(await loadIcon('../evil')).toBeNull()
  })

  it('draws a Solution’s icon once the catalogue has loaded', async () => {
    const wrapper = mount(ShellIcon, { props: { name: 'kanban' } })

    await vi.waitFor(() => expect(wrapper.find('svg').classes()).toContain('lucide-kanban'))
  })

  it('keeps a circle for an unknown name, or nothing without the fallback', async () => {
    const circle = mount(ShellIcon, { props: { name: 'no-such-icon-at-all' } })
    const bare = mount(ShellIcon, { props: { name: 'no-such-icon-at-all', fallback: false } })
    await new Promise((done) => setTimeout(done, 50))

    expect(circle.find('svg').classes()).toContain('lucide-circle')
    expect(bare.find('svg').exists()).toBe(false)
  })
})
