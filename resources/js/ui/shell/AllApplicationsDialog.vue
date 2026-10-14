<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { toast } from 'vue-sonner'
import { ChevronDown, ChevronUp, Pin, PinOff, Search } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { iconFor } from './icons'
import { interpolate } from './nav'
import { filterApplications, move, togglePin } from './rail'
import type { ApplicationsTranslations, ShellApplication, ShellApplications } from './types'

/**
 * "All applications": every application the user may open, searchable, each with a pin toggle, and the pinned ones in
 * the rail's order with buttons to move them. A change is shown at once and sent as the whole new rail; the shell
 * redraws from the answer, and a refused change is undone with an error toast.
 */
const props = defineProps<{
  data: ShellApplications
  labels: ApplicationsTranslations
}>()

const open = defineModel<boolean>('open', { required: true })

const text = ref('')
const rail = ref<string[]>([...props.data.rail])

watch(
  () => props.data.rail,
  (value) => {
    rail.value = [...value]
  },
)

watch(open, (isOpen) => {
  if (!isOpen) {
    text.value = ''
  }
})

const found = computed(() => filterApplications(props.data.items, text.value))
const pinned = computed(() => rail.value.flatMap((key) => props.data.items.find((item) => item.key === key) ?? []))

function save(next: string[]): void {
  rail.value = next
  router.put(
    props.data.railUrl,
    { applications: next },
    {
      preserveScroll: true,
      preserveState: true,
      only: ['applications'],
      // Refused (an application the user may no longer open): the rail goes back to what the server holds.
      onError: () => {
        rail.value = [...props.data.rail]
        toast.error(props.labels.save_failed)
      },
    },
  )
}

function isPinned(item: ShellApplication): boolean {
  return rail.value.includes(item.key)
}

</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="gap-3.5 sm:max-w-xl" data-test="all-applications">
      <DialogHeader>
        <DialogTitle class="font-display text-xl font-semibold">{{ labels.all }}</DialogTitle>
        <DialogDescription class="text-faint-foreground text-xs">{{ labels.note }}</DialogDescription>
      </DialogHeader>

      <label class="border-border bg-surface-muted focus-within:border-ring flex min-h-[38px] items-center gap-2 rounded-md border px-2.5">
        <Search class="text-faint-foreground size-4 shrink-0" aria-hidden="true" />
        <span class="sr-only">{{ labels.find }}</span>
        <input
          v-model="text"
          type="search"
          :placeholder="labels.find"
          maxlength="100"
          class="placeholder:text-muted-foreground h-9 w-full bg-transparent text-sm outline-hidden"
          data-test="all-applications-search"
        />
      </label>

      <ul v-if="found.length > 0" class="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
        <li v-for="item in found" :key="item.key" class="bg-card flex flex-col items-center gap-2 rounded-xl border px-1.5 pt-3.5 pb-2">
          <component
            :is="item.external ? 'a' : Link"
            :href="item.href"
            class="focus-visible:ring-ring/30 flex w-full flex-col items-center gap-2 rounded-lg outline-none focus-visible:ring-3"
            @click="open = false"
          >
            <span class="bg-accent text-accent-foreground flex size-11 items-center justify-center rounded-xl">
              <component :is="iconFor(item.icon)" class="size-[22px]" aria-hidden="true" />
            </span>
            <span class="text-center text-[12.5px] leading-tight font-semibold">{{ item.label }}</span>
            <span class="text-faint-foreground text-center text-[11px] leading-tight">{{ item.scope }}</span>
          </component>
          <Button
            variant="ghost"
            size="sm"
            class="text-faint-foreground h-7 gap-1 px-2 text-[11px]"
            :aria-pressed="isPinned(item)"
            :aria-label="interpolate(isPinned(item) ? labels.unpin_named : labels.pin_named, { name: item.label })"
            :data-test="`pin-${item.key}`"
            @click="save(togglePin(rail, item.key))"
          >
            <PinOff v-if="isPinned(item)" class="size-3.5" aria-hidden="true" />
            <Pin v-else class="size-3.5" aria-hidden="true" />
            {{ isPinned(item) ? labels.pinned : labels.pin }}
          </Button>
        </li>
      </ul>
      <p v-else class="text-muted-foreground py-6 text-center text-sm" role="status">{{ labels.empty }}</p>

      <section class="flex flex-col gap-1.5" :aria-label="labels.rail_title">
        <h3 class="font-display text-faint-foreground text-[12.5px] font-semibold tracking-[0.04em] uppercase">{{ labels.rail_title }}</h3>
        <p v-if="pinned.length === 0" class="text-muted-foreground text-sm">{{ labels.rail_empty }}</p>
        <ol v-else class="flex flex-col gap-1">
          <li v-for="(item, index) in pinned" :key="item.key" class="flex min-h-9 items-center gap-2 rounded-md border px-2.5" :data-test="`rail-order-${item.key}`">
            <component :is="iconFor(item.icon)" class="text-sidebar-foreground size-4 shrink-0" aria-hidden="true" />
            <span class="min-w-0 flex-1 truncate text-sm">{{ item.label }}</span>
            <Button
              variant="ghost"
              size="icon-sm"
              :disabled="index === 0"
              :aria-label="interpolate(labels.move_up, { name: item.label })"
              @click="save(move(rail, item.key, -1))"
            >
              <ChevronUp aria-hidden="true" />
            </Button>
            <Button
              variant="ghost"
              size="icon-sm"
              :disabled="index === pinned.length - 1"
              :aria-label="interpolate(labels.move_down, { name: item.label })"
              @click="save(move(rail, item.key, 1))"
            >
              <ChevronDown aria-hidden="true" />
            </Button>
            <Button
              variant="ghost"
              size="icon-sm"
              :aria-label="interpolate(labels.unpin_named, { name: item.label })"
              @click="save(togglePin(rail, item.key))"
            >
              <PinOff aria-hidden="true" />
            </Button>
          </li>
        </ol>
      </section>
    </DialogContent>
  </Dialog>
</template>
