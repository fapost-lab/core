// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ActivityChart from './ActivityChart.vue'

const labels = { executed: 'Executed', failed: 'Failed', day: 'Day', empty: 'Nothing ran.' }

describe('ActivityChart', () => {
  it('hides the screen-reader table with a wrapper, so the table cannot stretch the page', () => {
    const days = Array.from({ length: 14 }, (_, index) => ({ date: `2026-10-${String(index + 1).padStart(2, '0')}`, executed: index, failed: 0 }))
    const wrapper = mount(ActivityChart, { props: { days, locale: 'en', labels } })

    const table = wrapper.get('table')
    expect(table.classes()).not.toContain('sr-only')
    expect(table.element.parentElement?.classList.contains('sr-only')).toBe(true)
    expect(table.findAll('tbody tr')).toHaveLength(14)
  })
})
