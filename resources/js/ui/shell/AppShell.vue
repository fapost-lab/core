<script setup lang="ts">
import ShellBanners from './ShellBanners.vue'
import ShellHeader from './ShellHeader.vue'
import ShellSidebar from './ShellSidebar.vue'

/**
 * The frame of every console page: side menu, header with breadcrumbs and the language, theme and user controls,
 * and the banners above the page. Pages choose it as a persistent layout (`defineOptions({ layout: AppShell })`),
 * so the menu keeps its state while the page changes.
 *
 * The `rail` slot, left of the menu, is reserved for the application strip of a later phase; it is empty now.
 */
</script>

<template>
  <div class="bg-background text-foreground flex min-h-screen">
    <slot name="rail" />

    <aside class="bg-sidebar text-sidebar-foreground border-sidebar-border sticky top-0 hidden h-screen w-64 shrink-0 border-r lg:block">
      <ShellSidebar />
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
      <ShellBanners />
      <ShellHeader />
      <main class="flex-1 p-4 sm:p-6">
        <slot />
      </main>
    </div>
  </div>
</template>
