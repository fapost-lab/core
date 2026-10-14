<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { Grip } from '@lucide/vue'
import { cn } from '@fapost/ui/lib/utils'
import AllApplicationsDialog from './AllApplicationsDialog.vue'
import ShellIcon from './ShellIcon.vue'
import { railApplications } from './rail'
import type { ShellPageProps } from './types'

/**
 * The application rail left of the side menu (the app switcher): the brand, the applications the user pinned in their
 * order (and the current one, pinned or not), and "All applications", where the user finds, pins and orders them.
 * An application whose home is still Filament's is a plain link, a full page load.
 */
const page = usePage<ShellPageProps>()

const data = computed(() => page.props.applications)
const t = computed(() => page.props.translations.console.applications)
const items = computed(() => (data.value ? railApplications(data.value) : []))
const allOpen = ref(false)

const itemClass = (active: boolean): string =>
  cn(
    'focus-visible:ring-ring/30 relative flex size-[52px] flex-col items-center justify-center gap-[3px] rounded-xl transition-colors outline-none focus-visible:ring-3',
    active ? 'bg-card text-accent-foreground shadow-sm' : 'text-sidebar-foreground hover:bg-card/60 hover:text-foreground',
  )
</script>

<template>
  <nav v-if="data" :aria-label="t.rail" class="bg-rail border-rail-border flex h-full w-[68px] shrink-0 flex-col items-center gap-1.5 border-r py-3.5">
    <span class="bg-primary text-primary-foreground font-display mb-2.5 flex size-9 items-center justify-center rounded-[10px] text-[17px] font-bold" aria-hidden="true">F</span>

    <component
      :is="item.external ? 'a' : Link"
      v-for="item in items"
      :key="item.key"
      :href="item.href"
      :title="item.label"
      :aria-label="item.label"
      :aria-current="item.key === data.current ? 'page' : undefined"
      :class="itemClass(item.key === data.current)"
      :data-test="`rail-${item.key}`"
    >
      <span
        :class="['absolute top-3.5 -left-2 h-6 w-1 rounded-r-[3px]', item.key === data.current ? 'bg-primary' : 'bg-transparent']"
        aria-hidden="true"
      />
      <ShellIcon :name="item.icon" class="size-5" aria-hidden="true" />
      <span class="max-w-full truncate px-0.5 text-[10px] leading-none font-semibold" aria-hidden="true">{{ item.short }}</span>
    </component>

    <span class="bg-rail-dashed my-1.5 h-px w-7" aria-hidden="true" />

    <button
      type="button"
      :title="t.all"
      :aria-label="t.all"
      class="border-rail-dashed text-sidebar-foreground hover:bg-card/60 hover:text-foreground focus-visible:ring-ring/30 flex size-12 items-center justify-center rounded-xl border border-dashed transition-colors outline-none focus-visible:ring-3"
      data-test="rail-all"
      @click="allOpen = true"
    >
      <Grip class="size-5" aria-hidden="true" />
    </button>

    <AllApplicationsDialog v-model:open="allOpen" :data="data" :labels="t" />
  </nav>
</template>
