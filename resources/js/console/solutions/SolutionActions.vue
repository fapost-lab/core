<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { EllipsisVertical } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@fapost/ui/components/dropdown-menu'
import ShellIcon from '@fapost/ui/shell/ShellIcon.vue'
import { interpolate } from '@fapost/ui/shell'
import SolutionMount from './SolutionMount.vue'
import { actionHref, type ActionTarget, type ContextAction, type DialogAction } from './extensions'
import { useExtensions } from './useExtensions'

/**
 * The context actions Solutions declared on a record (`contact.view`, `conversation.view` in a screen's header,
 * `contact.row` in a list's row). A `link` is an Inertia link to the Solution's page for the record; a `dialog` mounts
 * the Solution's component in the kit's dialog with `{recordId, assistantId}`. In a row the actions sit behind one
 * menu button, so the row keeps its width. Nothing is drawn when no Solution declared anything here.
 */
const props = withDefaults(
  defineProps<{
    target: ActionTarget
    recordId: string
    /** `row`: one menu button for a list's row; `header`: a button per action. */
    variant?: 'header' | 'row'
    /** The record's name, for the row menu's label. */
    name?: string
  }>(),
  { variant: 'header', name: '' },
)

const page = usePage<{ translations: { console: { extensions?: { row_actions?: string } } } }>()
const { items, assistantId } = useExtensions('actions', props.target)

const actions = computed(() =>
  items.value.flatMap((action: ContextAction): { action: ContextAction; href: string | null }[] => {
    if (action.kind === 'dialog') {
      return [{ action, href: null }]
    }

    const href = actionHref(action, props.recordId)

    return href === null ? [] : [{ action, href }]
  }),
)

const rowLabel = computed(() => interpolate(page.props.translations.console.extensions?.row_actions ?? 'More actions: :name', { name: props.name || props.recordId }))

const opened = ref<DialogAction | null>(null)
const dialogOpen = computed({
  get: () => opened.value !== null,
  set: (value: boolean) => {
    if (!value) {
      opened.value = null
    }
  },
})
</script>

<template>
  <template v-if="actions.length > 0">
    <DropdownMenu v-if="variant === 'row'">
      <DropdownMenuTrigger as-child>
        <Button variant="ghost" size="icon-sm" :aria-label="rowLabel" data-solution-actions="row">
          <EllipsisVertical aria-hidden="true" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <template v-for="{ action, href } in actions" :key="action.id">
          <DropdownMenuItem v-if="href !== null" as-child>
            <Link :href="href" :data-solution-action="action.id">
              <ShellIcon v-if="action.icon" :name="action.icon" :fallback="false" aria-hidden="true" />
              {{ action.label }}
            </Link>
          </DropdownMenuItem>
          <DropdownMenuItem v-else-if="action.kind === 'dialog'" :data-solution-action="action.id" @select="opened = action">
            <ShellIcon v-if="action.icon" :name="action.icon" :fallback="false" aria-hidden="true" />
            {{ action.label }}
          </DropdownMenuItem>
        </template>
      </DropdownMenuContent>
    </DropdownMenu>

    <template v-else>
      <template v-for="{ action, href } in actions" :key="action.id">
        <Button v-if="href !== null" as-child variant="outline" size="sm">
          <Link :href="href" :data-solution-action="action.id">
            <ShellIcon v-if="action.icon" :name="action.icon" :fallback="false" aria-hidden="true" />
            {{ action.label }}
          </Link>
        </Button>
        <Button v-else-if="action.kind === 'dialog'" type="button" variant="outline" size="sm" :data-solution-action="action.id" @click="opened = action">
          <ShellIcon v-if="action.icon" :name="action.icon" :fallback="false" aria-hidden="true" />
          {{ action.label }}
        </Button>
      </template>
    </template>

    <Dialog v-model:open="dialogOpen">
      <DialogContent v-if="opened" :aria-describedby="undefined" class="sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>{{ opened.label }}</DialogTitle>
        </DialogHeader>
        <SolutionMount :key="opened.id" :component="opened.component" :record-id="recordId" :assistant-id="assistantId" @failed="opened = null" />
      </DialogContent>
    </Dialog>
  </template>
</template>
