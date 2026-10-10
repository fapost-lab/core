<script setup lang="ts">
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { EllipsisVertical, Folder, FolderOpen, Library, Pencil, Trash2 } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@fapost/ui/components/dropdown-menu'
import { cn } from '@fapost/ui/lib/utils'
import { interpolate } from '@fapost/ui/shell'
import type { FolderNode, MediaPageProps } from './types'

/**
 * The folder tree beside the files: the root, then every folder indented by its depth. Opening a folder, renaming and
 * deleting it are the page's business; the tree only says which was asked for.
 */
const props = defineProps<{
  tree: readonly FolderNode[]
  currentId: string | null
  canManage: boolean
}>()

const emit = defineEmits<{
  open: [folderId: string | null]
  rename: [folder: FolderNode]
  delete: [folder: FolderNode]
}>()

const page = usePage<MediaPageProps>()
const t = computed(() => page.props.translations.console.media)

function itemClass(active: boolean): string {
  return cn(
    'flex min-w-0 flex-1 items-center gap-2 rounded-md px-2 py-1.5 text-left text-sm transition-colors',
    active ? 'bg-primary-soft text-primary-hover font-semibold' : 'hover:bg-accent text-foreground',
  )
}
</script>

<template>
  <nav :aria-label="t.folders" class="flex flex-col gap-0.5">
    <button type="button" :class="itemClass(props.currentId === null)" :aria-current="props.currentId === null ? 'page' : undefined" @click="emit('open', null)">
      <Library class="size-4 shrink-0" aria-hidden="true" />
      <span class="truncate">{{ t.root }}</span>
    </button>

    <div v-for="folder in props.tree" :key="folder.id" class="group flex items-center gap-1" :style="{ paddingLeft: `${(folder.depth + 1) * 12}px` }">
      <button
        type="button"
        :class="itemClass(props.currentId === folder.id)"
        :aria-current="props.currentId === folder.id ? 'page' : undefined"
        :title="folder.path"
        @click="emit('open', folder.id)"
      >
        <component :is="props.currentId === folder.id ? FolderOpen : Folder" class="size-4 shrink-0" aria-hidden="true" />
        <span class="truncate">{{ folder.name }}</span>
      </button>

      <DropdownMenu v-if="props.canManage">
        <DropdownMenuTrigger as-child>
          <Button variant="ghost" size="icon-xs" :aria-label="interpolate(t.folder_actions_for, { name: folder.name })">
            <EllipsisVertical aria-hidden="true" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          <DropdownMenuItem @select="emit('rename', folder)">
            <Pencil aria-hidden="true" />
            {{ t.actions.rename }}
          </DropdownMenuItem>
          <DropdownMenuItem variant="destructive" @select="emit('delete', folder)">
            <Trash2 aria-hidden="true" />
            {{ t.delete_folder.confirm }}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>

    <p v-if="props.tree.length === 0" class="text-muted-foreground px-2 py-1.5 text-[13px]">{{ t.no_folders }}</p>
  </nav>
</template>
