<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { cn } from '@fapost/ui/lib/utils'
import { iconFor } from './icons'

/**
 * One entry of the side menu. A screen that has not left Filament yet (`external`) is a plain link, so the browser
 * loads it as a page; handing it to Inertia would try to render Filament's HTML as a component.
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
    'flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-sm transition-colors',
    props.active
      ? 'bg-sidebar-accent text-sidebar-accent-foreground font-medium'
      : 'text-sidebar-foreground hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground',
  )
</script>

<template>
  <a v-if="external" :href="href" :class="linkClass()" :aria-current="active ? 'page' : undefined">
    <component :is="iconFor(icon ?? '')" v-if="icon" class="size-4 shrink-0" aria-hidden="true" />
    <span class="min-w-0 flex-1 truncate">{{ label }}</span>
    <span v-if="badge" class="bg-danger text-danger-foreground rounded-full px-1.5 text-xs font-medium">{{ badge }}</span>
  </a>
  <Link v-else :href="href" :class="linkClass()" :aria-current="active ? 'page' : undefined">
    <component :is="iconFor(icon ?? '')" v-if="icon" class="size-4 shrink-0" aria-hidden="true" />
    <span class="min-w-0 flex-1 truncate">{{ label }}</span>
    <span v-if="badge" class="bg-danger text-danger-foreground rounded-full px-1.5 text-xs font-medium">{{ badge }}</span>
  </Link>
</template>
