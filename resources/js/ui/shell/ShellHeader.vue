<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { ChevronRight, Menu } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { Sheet, SheetContent, SheetDescription, SheetTitle, SheetTrigger } from '@fapost/ui/components/sheet'
import LanguageMenu from './LanguageMenu.vue'
import ShellSidebar from './ShellSidebar.vue'
import ThemeToggle from './ThemeToggle.vue'
import UserMenu from './UserMenu.vue'
import { breadcrumbs } from './nav'
import type { ShellPageProps } from './types'

const page = usePage<ShellPageProps>()

const t = computed(() => page.props.translations.console)
const crumbs = computed(() => {
  const navigation = page.props.navigation
  const root = page.props.assistants?.current.name ?? t.value.breadcrumbs.admin

  return breadcrumbs(root, navigation?.groups ?? [], page.url)
})
</script>

<template>
  <header class="bg-background/90 sticky top-0 z-10 flex h-14 shrink-0 items-center gap-3 border-b px-4 backdrop-blur">
    <Sheet>
      <SheetTrigger as-child>
        <Button variant="ghost" size="icon-sm" class="lg:hidden" :aria-label="t.navigation.menu">
          <Menu aria-hidden="true" />
        </Button>
      </SheetTrigger>
      <SheetContent side="left" class="bg-sidebar w-72 p-0">
        <SheetTitle class="sr-only">{{ t.navigation.menu }}</SheetTitle>
        <SheetDescription class="sr-only">{{ t.navigation.menu }}</SheetDescription>
        <ShellSidebar />
      </SheetContent>
    </Sheet>

    <nav aria-label="Breadcrumb" class="min-w-0 flex-1">
      <ol class="text-muted-foreground flex items-center gap-1.5 text-sm">
        <li v-for="(crumb, index) in crumbs" :key="`${index}-${crumb}`" class="flex min-w-0 items-center gap-1.5">
          <ChevronRight v-if="index > 0" class="size-3.5 shrink-0" aria-hidden="true" />
          <span :class="['truncate', index === crumbs.length - 1 ? 'text-foreground font-medium' : '']">{{ crumb }}</span>
        </li>
      </ol>
    </nav>

    <div class="flex items-center gap-1">
      <LanguageMenu
        :url="page.props.shell.localeUrl"
        :current="page.props.locale"
        :locales="page.props.shell.locales"
        :labels="t.language"
      />
      <ThemeToggle :labels="t.theme" />
      <UserMenu
        v-if="page.props.auth.user"
        :user="page.props.auth.user"
        :logout-url="page.props.shell.logoutUrl"
        :labels="t.user_menu"
      />
    </div>
  </header>
</template>
