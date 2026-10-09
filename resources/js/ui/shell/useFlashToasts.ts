import { onBeforeUnmount, toRaw } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import { toastsFor, type FlashMessages } from './flash'

/**
 * Shows Inertia's flash data (`Inertia::flash('success', ...)` on the server) as toasts.
 *
 * Flash data is not kept in the browser's history, so Back and Forward never bring a toast back; the router fires
 * its `flash` event once per response that carries some. The page's own flash is read once on setup, for a layout
 * that mounts after the event fired, and the set of shown objects keeps the same flash from showing twice.
 */
export function useFlashToasts(): void {
  const shown = new WeakSet<object>()

  function show(flash: object | null | undefined): void {
    if (!flash || shown.has(toRaw(flash))) {
      return
    }

    shown.add(toRaw(flash))

    for (const { kind, message } of toastsFor(flash as FlashMessages)) {
      toast[kind](message)
    }
  }

  show(usePage().flash)

  const stop = router.on('flash', (event) => show(event.detail.flash))

  onBeforeUnmount(stop)
}
