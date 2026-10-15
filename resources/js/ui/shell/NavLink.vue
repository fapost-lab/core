<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { cn } from '@fapost/ui/lib/utils'
import ShellIcon from './ShellIcon.vue'

/**
 * One entry of the side menu. An entry that leads off the console (`external`) is a plain link, so the browser
 * loads it as a page; handing it to Inertia would try to render that page's HTML as a component.
 */
const props = defineProps<{
  href: string
  label: string
  icon?: string
  badge?: string | null
  external?: boolean
  active?: boolean
}>()

const linkClass = (): string =>
  cn(
    'flex min-h-9 items-center gap-2.5 rounded-md px-2.5 text-[13.5px] transition-colors',
    props.active
      ? 'bg-sidebar-accent text-sidebar-accent-foreground font-semibold'
      : 'text-sidebar-foreground hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground',
  )
</script>

<template>
  <a v-if="external" :href="href" :class="linkClass()" :aria-current="active ? 'page' : undefined">
    <ShellIcon :name="icon" v-if="icon" class="size-[18px] shrink-0" aria-hidden="true" />
    <span class="min-w-0 flex-1 truncate">{{ label }}</span>
    <span v-if="badge" class="bg-primary text-primary-foreground inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[11px] font-semibold">{{ badge }}</span>
  </a>
  <Link v-else :href="href" :class="linkClass()" :aria-current="active ? 'page' : undefined">
    <ShellIcon :name="icon" v-if="icon" class="size-[18px] shrink-0" aria-hidden="true" />
    <span class="min-w-0 flex-1 truncate">{{ label }}</span>
    <span v-if="badge" class="bg-primary text-primary-foreground inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[11px] font-semibold">{{ badge }}</span>
  </Link>
</template>
