<script setup lang="ts">
import { computed, inject } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Menu } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { Sheet, SheetContent, SheetDescription, SheetTitle, SheetTrigger } from '@fapost/ui/components/sheet'
import AppRail from './AppRail.vue'
import LanguageMenu from './LanguageMenu.vue'
import NotificationBell from './NotificationBell.vue'
import ShellSearch from './ShellSearch.vue'
import ShellSidebar from './ShellSidebar.vue'
import ThemeToggle from './ThemeToggle.vue'
import UserMenu from './UserMenu.vue'
import { breadcrumbs } from './nav'
import { SHELL_SLOT } from './slots'
import type { ShellPageProps } from './types'

const page = usePage<ShellPageProps>()
// What the application adds to the header (`slots.ts`); nothing without a provider.
const slotted = inject(SHELL_SLOT, null)

const t = computed(() => page.props.translations.console)
const crumbs = computed(() => {
  const navigation = page.props.navigation
  const applications = page.props.applications
  const application = applications?.items.find((item) => item.key === applications.current)
  const root = page.props.assistants?.current.name ?? application?.label ?? t.value.breadcrumbs.admin

  return breadcrumbs(root, navigation?.groups ?? [], page.url)
})
</script>

<template>
  <header class="bg-card/70 sticky top-0 z-10 flex min-h-14 shrink-0 items-center gap-3 border-b px-4 backdrop-blur sm:px-6 lg:px-8">
    <Sheet>
      <SheetTrigger as-child>
        <Button variant="ghost" size="icon-sm" class="lg:hidden" :aria-label="t.navigation.menu">
          <Menu aria-hidden="true" />
        </Button>
      </SheetTrigger>
      <SheetContent side="left" class="bg-sidebar flex w-[310px] flex-row gap-0 p-0 sm:max-w-[310px]">
        <SheetTitle class="sr-only">{{ t.navigation.menu }}</SheetTitle>
        <SheetDescription class="sr-only">{{ t.navigation.menu }}</SheetDescription>
        <AppRail />
        <div class="min-w-0 flex-1">
          <ShellSidebar />
        </div>
      </SheetContent>
    </Sheet>

    <nav aria-label="Breadcrumb" class="min-w-0 flex-1">
      <ol class="text-faint-foreground flex items-center gap-2 text-[13px]">
        <li v-for="(crumb, index) in crumbs" :key="`${index}-${crumb}`" class="flex min-w-0 items-center gap-2">
          <span v-if="index > 0" class="shrink-0" aria-hidden="true">/</span>
          <span :class="['truncate', index === crumbs.length - 1 ? 'text-foreground font-medium' : '']">{{ crumb }}</span>
        </li>
      </ol>
    </nav>

    <div class="flex items-center gap-1">
      <component :is="slotted" v-if="slotted" place="header" />
      <ShellSearch v-if="page.props.navigation?.searchUrl" :url="page.props.navigation.searchUrl" :labels="t.search" />
      <LanguageMenu
        :url="page.props.shell.localeUrl"
        :current="page.props.locale"
        :locales="page.props.shell.locales"
        :labels="t.language"
      />
      <ThemeToggle :labels="t.theme" />
      <NotificationBell
        v-if="page.props.notifications"
        :notifications="page.props.notifications"
        :locale="page.props.locale"
        :labels="t.notifications"
      />
      <UserMenu
        v-if="page.props.auth.user"
        :user="page.props.auth.user"
        :logout-url="page.props.shell.logoutUrl"
        :labels="t.user_menu"
      />
    </div>
  </header>
</template>
