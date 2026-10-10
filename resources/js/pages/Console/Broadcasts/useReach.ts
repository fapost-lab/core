import { onBeforeUnmount, ref, watch } from 'vue'
import { useHttp } from '@inertiajs/vue3'
import { isCountable } from './audience'
import type { BroadcastAudience } from './types'

export type ReachState = 'idle' | 'loading' | 'ready' | 'invalid' | 'error'

/** How long the form waits after the last change of the audience before it asks the server to count. */
export const REACH_DEBOUNCE_MS = 500

/** The first message the reach endpoint gave for a `target_*` field (a list item is `target_tags.0`), or null. */
export function firstTargetError(errors: Record<string, string | undefined>): string | null {
  const key = Object.keys(errors).find((name) => name.startsWith('target_') && errors[name])

  return key === undefined ? null : (errors[key] ?? null)
}

/**
 * The reach of an audience: how many contacts would receive a broadcast to it now, counted by the server.
 *
 * `refresh()` asks at once (the send dialog, a page that opens with an audience); unless `auto` is off, a change of the
 * audience asks after a pause, cancelling the request still on its way, and only the answer to the latest question is kept. The number
 * is an estimate: the audience is fixed when the run starts.
 */
export function useReach(url: () => string, audience: () => BroadcastAudience, options: { debounce?: number; auto?: boolean } = {}) {
  const count = ref<number | null>(null)
  const state = ref<ReachState>('idle')
  // The server's refusal of the audience (a tag or a segment that is gone), shown instead of "could not count".
  const message = ref<string | null>(null)
  const http = useHttp<{ target_type: string; target_tags: string[]; target_segment_id: string }, { count: number }>({
    target_type: 'all',
    target_tags: [],
    target_segment_id: '',
  })

  let timer: ReturnType<typeof setTimeout> | null = null
  let latest = 0

  function clearTimer(): void {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }

  function refresh(): void {
    clearTimer()
    http.cancel()

    const current = audience()
    const ticket = ++latest

    if (!isCountable(current)) {
      count.value = null
      state.value = 'idle'

      return
    }

    state.value = 'loading'
    message.value = null
    http.target_type = current.targetType
    http.target_tags = current.targetType === 'tags' ? current.targetTags : []
    http.target_segment_id = current.targetType === 'segment' ? (current.targetSegmentId ?? '') : ''

    http
      .get(url())
      .then((response) => {
        if (ticket !== latest) {
          return
        }

        if (typeof response?.count === 'number') {
          count.value = response.count
          state.value = 'ready'
        } else {
          // `useHttp` resolves nothing on a 422 and keeps the validation errors on itself.
          count.value = null
          message.value = firstTargetError(http.errors)
          state.value = message.value === null ? 'error' : 'invalid'
        }
      })
      .catch(() => {
        // A request cancelled by a newer one is not an error; only the latest question's failure is shown.
        if (ticket === latest) {
          count.value = null
          state.value = 'error'
        }
      })
  }

  function schedule(): void {
    clearTimer()
    http.cancel()
    latest++
    state.value = isCountable(audience()) ? 'loading' : 'idle'
    count.value = null
    message.value = null
    timer = setTimeout(refresh, options.debounce ?? REACH_DEBOUNCE_MS)
  }

  // `auto: false` leaves asking to the caller (the send dialog counts once, when it opens).
  if (options.auto !== false) {
    watch(() => JSON.stringify(audience()), schedule)
  }

  onBeforeUnmount(() => {
    clearTimer()
    http.cancel()
    latest++
  })

  return { count, state, message, refresh }
}
