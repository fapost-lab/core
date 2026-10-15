/** One notification as the bell lists it (`StaffNotificationInbox::latest()` plus its `readUrl`). Text is plain. */
export interface ShellNotification {
  id: string
  title: string
  body: string | null
  tone: NotificationTone
  /** A web address or a path on this host; the server drops anything else. */
  action: { label: string | null; url: string } | null
  createdAt: string | null
  read: boolean
  readUrl: string
}

export type NotificationTone = 'success' | 'info' | 'warning' | 'danger' | 'neutral'

/** How often the bell asks for the unread count while the tab is visible. */
export const NOTIFICATION_POLL_MS = 60_000

/** How long choosing a notification with a link waits for "read" to be saved before it leaves the page. */
export const READ_BEFORE_LEAVING_MS = 1000

/** Waits for the promise to settle, or for `ms`, whichever comes first; never rejects. */
export function settleWithin(promise: Promise<unknown>, ms: number): Promise<void> {
  return new Promise((resolve) => {
    const timer = setTimeout(resolve, ms)

    promise.then(
      () => {
        clearTimeout(timer)
        resolve()
      },
      () => {
        clearTimeout(timer)
        resolve()
      },
    )
  })
}

/** The largest count the badge spells out. */
export const BADGE_MAX = 99

/** The badge text: nothing for zero, "99+" past the largest count it spells out. */
export function badgeText(unread: number): string | null {
  if (!Number.isFinite(unread) || unread <= 0) {
    return null
  }

  return unread > BADGE_MAX ? `${BADGE_MAX}+` : String(Math.trunc(unread))
}

/** The list after one notification was read: the same order, that one marked read. */
export function markedRead(items: ShellNotification[], id: string): ShellNotification[] {
  return items.map((item) => (item.id === id ? { ...item, read: true } : item))
}

/** The list after everything was read. */
export function allMarkedRead(items: ShellNotification[]): ShellNotification[] {
  return items.map((item) => (item.read ? item : { ...item, read: true }))
}

