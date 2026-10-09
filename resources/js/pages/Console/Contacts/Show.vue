<script lang="ts">
import { AppShell } from '@fapost/ui/shell'

export default { layout: AppShell }
</script>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import { ArrowLeft, Tag, Users } from '@lucide/vue'
import { Badge } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { Card, CardContent, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { relativeTime } from '@fapost/ui/lib/relative-time'
import CopyButton from './CopyButton.vue'
import GroupsDialog from './GroupsDialog.vue'
import TagsDialog from './TagsDialog.vue'
import type { ContactCardData, ContactCardField, ContactDetails, ContactGroupRef, ContactsPageProps } from './types'

const props = defineProps<{
  contact: ContactDetails
  card: ContactCardData
  can: { update: boolean }
  tagSuggestions?: string[]
  groupOptions?: ContactGroupRef[]
  urls: { index: string; tags?: string; groups?: string }
}>()

const page = usePage<ContactsPageProps>()
const t = computed(() => page.props.translations.console.contacts)

const title = computed(() => props.contact.name ?? props.contact.externalId)
const platformLabel = computed(() => t.value.platforms[props.contact.platform] ?? props.contact.platform)

const tagsOpen = ref(false)
const groupsOpen = ref(false)

// A section with nothing in it is not drawn; the profile and the group sections start closed past three groups.
const profileOpen = computed(() => !props.card.collapsed)

function hasValue(field: ContactCardField): boolean {
  return field.value !== ''
}
</script>

<template>
  <Head :title="title" />

  <div class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div class="flex flex-col gap-2">
      <Button as-child variant="ghost" size="sm" class="-ml-2 w-fit">
        <Link :href="urls.index">
          <ArrowLeft aria-hidden="true" />
          {{ t.back }}
        </Link>
      </Button>
      <h1 class="font-display text-2xl font-semibold tracking-wide break-all uppercase">{{ title }}</h1>
    </div>

    <Card>
      <CardHeader>
        <CardTitle>{{ t.view.identity }}</CardTitle>
      </CardHeader>
      <CardContent class="flex flex-col gap-6">
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.platform }}</dt>
            <dd><Badge variant="secondary">{{ platformLabel }}</Badge></dd>
          </div>
          <div class="flex min-w-0 flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.external_id }}</dt>
            <dd class="flex items-center gap-1">
              <span class="truncate font-mono text-sm">{{ contact.externalId }}</span>
              <CopyButton :value="contact.externalId" />
            </dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.language }}</dt>
            <dd>
              <Badge v-if="contact.language" variant="outline">{{ contact.language }}</Badge>
              <span v-else class="text-muted-foreground">{{ t.view.empty_value }}</span>
            </dd>
          </div>
          <div class="flex min-w-0 flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.username }}</dt>
            <dd class="truncate">
              <span v-if="contact.username">{{ contact.username }}</span>
              <span v-else class="text-muted-foreground">{{ t.view.empty_value }}</span>
            </dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.created_at }}</dt>
            <dd>
              <span v-if="contact.createdAt" :title="contact.createdAt">{{ relativeTime(contact.createdAt, page.props.locale) }}</span>
              <span v-else class="text-muted-foreground">{{ t.view.empty_value }}</span>
            </dd>
          </div>
          <div class="flex flex-col gap-1">
            <dt class="text-muted-foreground text-sm">{{ t.view.updated_at }}</dt>
            <dd>
              <span v-if="contact.updatedAt" :title="contact.updatedAt">{{ relativeTime(contact.updatedAt, page.props.locale) }}</span>
              <span v-else class="text-muted-foreground">{{ t.view.empty_value }}</span>
            </dd>
          </div>
        </dl>

        <div class="flex flex-col gap-4">
          <div class="flex flex-col gap-2">
            <div class="flex items-center justify-between gap-2">
              <h2 class="text-muted-foreground text-sm">{{ t.view.tags }}</h2>
              <Button v-if="can.update && urls.tags" type="button" variant="outline" size="sm" @click="tagsOpen = true">
                <Tag aria-hidden="true" />
                {{ t.view.manage_tags }}
              </Button>
            </div>
            <ul v-if="contact.tags.length" class="flex flex-wrap gap-1.5">
              <li v-for="tag in contact.tags" :key="tag"><Badge variant="secondary">{{ tag }}</Badge></li>
            </ul>
            <p v-else class="text-muted-foreground text-sm">{{ t.view.no_tags }}</p>
          </div>

          <div class="flex flex-col gap-2">
            <div class="flex items-center justify-between gap-2">
              <h2 class="text-muted-foreground text-sm">{{ t.view.groups }}</h2>
              <Button v-if="can.update && urls.groups && groupOptions" type="button" variant="outline" size="sm" @click="groupsOpen = true">
                <Users aria-hidden="true" />
                {{ t.view.manage_groups }}
              </Button>
            </div>
            <ul v-if="contact.groups.length" class="flex flex-wrap gap-1.5">
              <li v-for="group in contact.groups" :key="group.id"><Badge variant="secondary">{{ group.name }}</Badge></li>
            </ul>
            <p v-else class="text-muted-foreground text-sm">{{ t.view.no_groups }}</p>
          </div>
        </div>
      </CardContent>
    </Card>

    <Card v-if="card.profile.length">
      <CardContent>
        <details :open="profileOpen" class="group">
          <summary class="cursor-pointer font-semibold select-none">{{ t.view.profile }}</summary>
          <dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div v-for="field in card.profile" :key="field.key" class="flex min-w-0 flex-col gap-1">
              <dt class="text-muted-foreground text-sm break-words">{{ field.key }}</dt>
              <dd class="break-words whitespace-pre-wrap">
                <template v-if="hasValue(field)">{{ field.value }}</template>
                <span v-else class="text-muted-foreground">{{ t.view.empty_value }}</span>
              </dd>
            </div>
          </dl>
        </details>
      </CardContent>
    </Card>

    <Card v-for="group in card.groups" :key="group.key">
      <CardContent>
        <details :open="profileOpen" class="group">
          <summary class="cursor-pointer font-semibold select-none">
            {{ group.key }} <span class="text-muted-foreground font-normal">({{ group.fieldsLabel }})</span>
          </summary>
          <dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div v-for="field in group.fields" :key="field.key" class="flex min-w-0 flex-col gap-1">
              <dt class="text-muted-foreground text-sm break-words">{{ field.key }}</dt>
              <dd class="break-words whitespace-pre-wrap">
                <template v-if="hasValue(field)">{{ field.value }}</template>
                <span v-else class="text-muted-foreground">{{ t.view.empty_value }}</span>
              </dd>
            </div>
          </dl>
        </details>
      </CardContent>
    </Card>

    <Card v-if="card.meta.length">
      <CardContent>
        <details class="group">
          <summary class="cursor-pointer font-semibold select-none">{{ t.view.from_platform }}</summary>
          <dl class="mt-4 grid gap-4 sm:grid-cols-2">
            <div v-for="field in card.meta" :key="field.key" class="flex min-w-0 flex-col gap-1">
              <dt class="text-muted-foreground text-sm break-words">{{ field.key }}</dt>
              <dd class="break-words whitespace-pre-wrap">
                <template v-if="hasValue(field)">{{ field.value }}</template>
                <span v-else class="text-muted-foreground">{{ t.view.empty_value }}</span>
              </dd>
            </div>
          </dl>
        </details>
      </CardContent>
    </Card>

    <TagsDialog v-if="can.update && urls.tags" v-model:open="tagsOpen" :tags="contact.tags" :suggestions="tagSuggestions ?? []" :submit-url="urls.tags" />
    <GroupsDialog v-if="can.update && urls.groups && groupOptions" v-model:open="groupsOpen" :groups="contact.groups" :options="groupOptions" :submit-url="urls.groups" />
  </div>
</template>
