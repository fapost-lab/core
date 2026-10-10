<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { FileText, ListOrdered, UsersRound } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import { interpolate } from '@fapost/ui/shell'

interface DashboardTranslations {
  title: string
  sections: { summary: string; channels: string; operations: string }
  name: string
  status: string
  status_active: string
  status_inactive: string
  channels_intro: string
  operations_intro: string
  live_sessions: string
  errors_24h: string
  contact_limit: { title: string; reached: string; lifted: string; unknown: string; last: string }
}

const props = defineProps<{
  assistant: { id: string; name: string; isActive: boolean; channelsCount: number }
  operations: { liveSessions: number; errors24h: number; sessionsUrl: string | null; logsUrl: string | null }
  contactLimit: { people: number; messages: number; lastRefusedAt: string; limit: number | null; limitKnown: boolean } | null
}>()

const page = usePage<{ locale: string; translations: { console: { dashboard: DashboardTranslations } } }>()
const t = computed(() => page.props.translations.console.dashboard)
const lastRefusedAt = computed(() => (props.contactLimit ? relativeTime(props.contactLimit.lastRefusedAt, page.props.locale) : ''))
const status = computed(() => (props.assistant.isActive ? t.value.status_active : t.value.status_inactive))
</script>

<template>
  <Head :title="t.title" />

  <div class="flex w-full flex-col gap-5">
    <h1 class="font-display text-[28px] leading-tight font-semibold">{{ t.title }}</h1>

    <div class="grid gap-4 md:grid-cols-2">
      <Card v-if="contactLimit" class="md:col-span-2" data-test="contact-limit-card">
        <CardHeader>
          <CardTitle class="font-display flex items-center gap-2 tracking-wide uppercase">
            <UsersRound aria-hidden="true" class="size-4" />
            {{ t.contact_limit.title }}
          </CardTitle>
          <CardDescription>
            {{
              interpolate(!contactLimit.limitKnown ? t.contact_limit.unknown : contactLimit.limit === null ? t.contact_limit.lifted : t.contact_limit.reached, {
                people: contactLimit.people,
                messages: contactLimit.messages,
                limit: contactLimit.limit ?? '',
              })
            }}
          </CardDescription>
        </CardHeader>
        <CardContent class="text-muted-foreground text-sm">
          <p :title="contactLimit.lastRefusedAt">{{ interpolate(t.contact_limit.last, { time: lastRefusedAt }) }}</p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle class="font-display">{{ t.sections.summary }}</CardTitle>
        </CardHeader>
        <CardContent class="flex flex-col gap-2 text-sm">
          <p>{{ interpolate(t.name, { name: assistant.name }) }}</p>
          <p>{{ interpolate(t.status, { active: status }) }}</p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle class="font-display">{{ t.sections.channels }}</CardTitle>
          <CardDescription>{{ interpolate(t.channels_intro, { count: assistant.channelsCount }) }}</CardDescription>
        </CardHeader>
      </Card>

      <Card class="md:col-span-2">
        <CardHeader>
          <CardTitle class="font-display">{{ t.sections.operations }}</CardTitle>
          <CardDescription>{{ t.operations_intro }}</CardDescription>
        </CardHeader>
        <CardContent v-if="!operations.sessionsUrl || !operations.logsUrl" class="text-muted-foreground flex flex-col gap-1 text-sm">
          <p v-if="!operations.sessionsUrl">{{ interpolate(t.live_sessions, { count: operations.liveSessions }) }}</p>
          <p v-if="!operations.logsUrl">{{ interpolate(t.errors_24h, { count: operations.errors24h }) }}</p>
        </CardContent>
        <CardFooter class="flex flex-wrap gap-2">
          <Button v-if="operations.sessionsUrl" as-child variant="outline">
            <Link :href="operations.sessionsUrl">
              <ListOrdered aria-hidden="true" />
              {{ interpolate(t.live_sessions, { count: operations.liveSessions }) }}
            </Link>
          </Button>
          <Button v-if="operations.logsUrl" as-child variant="outline">
            <Link :href="operations.logsUrl">
              <FileText aria-hidden="true" />
              {{ interpolate(t.errors_24h, { count: operations.errors24h }) }}
            </Link>
          </Button>
        </CardFooter>
      </Card>
    </div>
  </div>
</template>
