import { onBeforeUnmount, onMounted, ref, type Ref } from 'vue'

/**
 * Whether the console is dark right now: the `.dark` class on <html> that theme.ts puts there as the toggle and the
 * system setting say. Reactive, so what takes a `theme` prop of its own (the toasts) follows the toggle.
 */
export function useIsDark(): Ref<boolean> {
  const dark = ref(false)
  let observer: MutationObserver | null = null

  const read = (): void => {
    dark.value = document.documentElement.classList.contains('dark')
  }

  onMounted(() => {
    read()
    observer = new MutationObserver(read)
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] })
  })

  onBeforeUnmount(() => observer?.disconnect())

  return dark
}
