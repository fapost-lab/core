<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { router, useHttp } from '@inertiajs/vue3'
import { Search } from '@lucide/vue'
import { ListboxContent, ListboxFilter, ListboxGroup, ListboxGroupLabel, ListboxItem, ListboxRoot } from 'reka-ui'
import { Button } from '@fapost/ui/components/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { SEARCH_DEBOUNCE_MS, hitValue, isPaletteShortcut, searchable, type SearchGroup, type SearchHit } from './search'

/**
 * The admin shell's search palette (⌘K / Ctrl+K), in place of Filament's global search: the server searches what the
 * user may see and answers a few hits per group; nothing is filtered here. A question still on its way is cancelled
 * by the next one, and only the latest answer is shown.
 */
const props = defineProps<{
  url: string
  labels: {
    open: string
    placeholder: string
    title: string
    description: string
    hint: string
    loading: string
    empty: string
    error: string
    groups: Record<SearchGroup['key'], string>
  }
}>()

type State = 'idle' | 'loading' | 'ready' | 'error'

const open = ref(false)
const text = ref('')
const groups = ref<SearchGroup[]>([])
const state = ref<State>('idle')
const http = useHttp<{ q: string }, { groups: SearchGroup[] }>({ q: '' })

let timer: ReturnType<typeof setTimeout> | null = null
let latest = 0

const isMac = typeof navigator !== 'undefined' && /mac/i.test(navigator.platform)
const shortcut = isMac ? '⌘K' : 'Ctrl K'

const message = computed(() => {
  switch (state.value) {
    case 'idle':
      return props.labels.hint
    case 'loading':
      return groups.value.length === 0 ? props.labels.loading : null
    case 'error':
      return props.labels.error
    default:
      return groups.value.length === 0 ? props.labels.empty : null
  }
})

function clearTimer(): void {
  if (timer !== null) {
    clearTimeout(timer)
    timer = null
  }
}

function ask(query: string): void {
  const ticket = ++latest

  http.q = query
  http
    .get(props.url)
    .then((response) => {
      if (ticket !== latest) {
        return
      }

      groups.value = response?.groups ?? []
      state.value = response ? 'ready' : 'error'
    })
    .catch(() => {
      // A question cancelled by a newer one is not an error.
      if (ticket === latest) {
        groups.value = []
        state.value = 'error'
      }
    })
}

watch(text, (value) => {
  clearTimer()
  http.cancel()
  latest++

  const query = searchable(value)

  if (query === null) {
    groups.value = []
    state.value = 'idle'

    return
  }

  state.value = 'loading'
  timer = setTimeout(() => ask(query), SEARCH_DEBOUNCE_MS)
})

watch(open, (isOpen) => {
  if (!isOpen) {
    text.value = ''
  }
})

function go(hit: SearchHit): void {
  open.value = false

  if (hit.external) {
    window.location.assign(hit.url)

    return
  }

  router.visit(hit.url)
}

function onKeydown(event: KeyboardEvent): void {
  if (isPaletteShortcut(event)) {
    event.preventDefault()
    open.value = !open.value
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  clearTimer()
  http.cancel()
  latest++
})
</script>

<template>
  <Button variant="outline" size="sm" class="text-muted-foreground gap-2" :aria-label="labels.open" aria-keyshortcuts="Meta+K Control+K" @click="open = true">
    <Search aria-hidden="true" />
    <span class="hidden sm:inline">{{ labels.open }}</span>
    <kbd class="bg-muted text-faint-foreground hidden rounded px-1.5 font-sans text-[11px] sm:inline">{{ shortcut }}</kbd>
  </Button>

  <Dialog v-model:open="open">
    <DialogContent class="top-[15%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-lg">
      <DialogHeader class="sr-only">
        <DialogTitle>{{ labels.title }}</DialogTitle>
        <DialogDescription>{{ labels.description }}</DialogDescription>
      </DialogHeader>

      <ListboxRoot class="flex flex-col" highlight-on-hover data-test="search-palette">
        <div class="flex h-11 items-center gap-2 border-b px-3">
          <Search class="text-muted-foreground size-4 shrink-0" aria-hidden="true" />
          <ListboxFilter
            v-model="text"
            auto-focus
            :placeholder="labels.placeholder"
            :aria-label="labels.title"
            maxlength="100"
            class="placeholder:text-muted-foreground h-full w-full bg-transparent text-sm outline-hidden"
          />
        </div>

        <ListboxContent class="max-h-80 overflow-y-auto p-1">
          <p v-if="message" class="text-muted-foreground px-2 py-6 text-center text-sm" role="status">{{ message }}</p>

          <ListboxGroup v-for="group in groups" :key="group.key" class="p-1">
            <ListboxGroupLabel class="text-muted-foreground px-2 py-1.5 text-xs font-medium">{{ labels.groups[group.key] }}</ListboxGroupLabel>
            <ListboxItem
              v-for="hit in group.items"
              :key="hitValue(group, hit)"
              :value="hitValue(group, hit)"
              class="data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground flex cursor-default flex-col items-start rounded-sm px-2 py-1.5 text-sm outline-hidden select-none"
              @select="go(hit)"
            >
              <span class="font-medium">{{ hit.title }}</span>
              <span v-if="hit.subtitle" class="text-muted-foreground text-xs">{{ hit.subtitle }}</span>
            </ListboxItem>
          </ListboxGroup>
        </ListboxContent>
      </ListboxRoot>
    </DialogContent>
  </Dialog>
</template>
