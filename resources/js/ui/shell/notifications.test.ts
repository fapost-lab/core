// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import NotificationBell from './NotificationBell.vue'
import { allMarkedRead, badgeText, markedRead, settleWithin, type ShellNotification } from './notifications'
import type { NotificationsTranslations } from './types'

function item(id: string, read = false): ShellNotification {
  return { id, title: id, body: null, tone: 'info', action: null, createdAt: null, read, readUrl: `/console/notifications/${id}/read` }
}

const labels: NotificationsTranslations = {
  label: 'Notifications',
  unread: ':count unread',
  title: 'Notifications',
  mark_all: 'Mark all as read',
  unread_item: 'Unread',
  open: 'Open',
  empty: 'No notifications yet.',
  loading: 'Loading…',
  error: 'Failed.',
  untitled: 'Notification',
}

function mountBell(unread: number) {
  return mount(NotificationBell, {
    props: {
      notifications: { unread, indexUrl: '/console/notifications', countUrl: '/console/notifications/count', readAllUrl: '/console/notifications/read' },
      locale: 'en',
      labels,
    },
  })
}

describe('badgeText', () => {
  it('shows nothing without unread notifications', () => {
    expect(badgeText(0)).toBeNull()
    expect(badgeText(-1)).toBeNull()
    expect(badgeText(Number.NaN)).toBeNull()
  })

  it('spells out the count up to 99, then 99+', () => {
    expect(badgeText(1)).toBe('1')
    expect(badgeText(99)).toBe('99')
    expect(badgeText(100)).toBe('99+')
  })
})

describe('marking read', () => {
  it('marks one notification read and keeps the order', () => {
    const items = markedRead([item('a'), item('b'), item('c')], 'b')

    expect(items.map((each) => [each.id, each.read])).toEqual([
      ['a', false],
      ['b', true],
      ['c', false],
    ])
  })

  it('marks every notification read', () => {
    expect(allMarkedRead([item('a'), item('b', true)]).every((each) => each.read)).toBe(true)
  })
})

describe('settleWithin', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('resolves when the promise settles, even when it rejects', async () => {
    await expect(settleWithin(Promise.resolve('ok'), 1000)).resolves.toBeUndefined()
    await expect(settleWithin(Promise.reject(new Error('no')), 1000)).resolves.toBeUndefined()
  })

  it('stops waiting after the time is up', async () => {
    vi.useFakeTimers()
    let settled = false
    const waiting = settleWithin(new Promise(() => {}), 1000).then(() => {
      settled = true
    })

    await vi.advanceTimersByTimeAsync(999)
    expect(settled).toBe(false)

    await vi.advanceTimersByTimeAsync(1)
    await waiting
    expect(settled).toBe(true)
  })
})

describe('NotificationBell', () => {
  let wrapper: ReturnType<typeof mountBell> | null = null

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
  })

  it('shows the unread count on the bell and in its label', () => {
    wrapper = mountBell(3)

    expect(wrapper.find('[data-test="notification-badge"]').text()).toBe('3')
    expect(wrapper.find('[data-test="notification-bell"]').attributes('aria-label')).toBe('Notifications (3 unread)')
  })

  it('shows no badge when everything is read', () => {
    wrapper = mountBell(0)

    expect(wrapper.find('[data-test="notification-badge"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="notification-bell"]').attributes('aria-label')).toBe('Notifications')
  })

  it('follows a new count from the next visit', async () => {
    wrapper = mountBell(2)

    await wrapper.setProps({ notifications: { unread: 150, indexUrl: '/n', countUrl: '/n/c', readAllUrl: '/n/r' } })

    expect(wrapper.find('[data-test="notification-badge"]').text()).toBe('99+')
  })
})
