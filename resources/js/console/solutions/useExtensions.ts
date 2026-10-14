import { computed, type ComputedRef } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { extensionsOn, type ConsoleExtensions } from './extensions'

/**
 * The items of the shared prop `extensions` on one target, following every visit, and the assistant the page is
 * open on (the id a mounted Solution component receives as `assistantId`).
 */
export function useExtensions<G extends keyof ConsoleExtensions, T extends keyof ConsoleExtensions[G] & string>(
  group: G,
  target: T,
): { items: ComputedRef<ConsoleExtensions[G][T]>; assistantId: ComputedRef<string | null> } {
  const page = usePage<{ extensions?: unknown; assistants?: { current?: { id?: string } } | null }>()

  return {
    items: computed(() => extensionsOn(page.props.extensions, group, target)),
    assistantId: computed(() => page.props.assistants?.current?.id ?? null),
  }
}
