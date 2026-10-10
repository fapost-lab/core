<script setup lang="ts">
import { computed, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { FormField } from '@fapost/ui/components/form-field'
import { Textarea } from '@fapost/ui/components/textarea'
import { interpolate } from '@fapost/ui/shell'
import { fallbackOf, formValues } from './translations'
import type { TranslationCell, TranslationRow, TranslationsPageProps } from './types'

/**
 * Sets the overrides of one key, a textarea per language. Every language is sent: an empty one removes its override,
 * so the cell falls back to the workspace's text (on an assistant) or the catalog default, shown as the placeholder.
 */
const props = defineProps<{
  row: TranslationRow | null
}>()

const open = defineModel<boolean>('open', { default: false })

const page = usePage<TranslationsPageProps>()
const t = computed(() => page.props.translations.console.translations)
const common = computed(() => page.props.translations.console.form)

const form = useForm<{ values: Record<string, string> }>({ values: {} })

// Each opening starts from the overrides the key has now.
watch(open, (isOpen) => {
  if (isOpen && props.row) {
    form.clearErrors()
    form.values = formValues(props.row)
  }
})

function hint(cell: TranslationCell): string {
  return cell.inherited !== null
    ? interpolate(t.value.edit_dialog.inherited_hint, { value: cell.inherited })
    : interpolate(t.value.edit_dialog.default_hint, { default: cell.default })
}

function errorOf(language: string): string | undefined {
  return (form.errors as Record<string, string | undefined>)[`values.${language}`]
}

function submit(): void {
  if (!props.row) {
    return
  }

  form.put(props.row.updateUrl, {
    preserveScroll: true,
    onSuccess: () => {
      open.value = false
    },
  })
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
      <DialogHeader>
        <DialogTitle class="font-mono text-base break-all">{{ row?.key }}</DialogTitle>
        <DialogDescription>
          <span v-if="row?.description" class="text-foreground block">{{ row.description }}</span>
          {{ t.edit_dialog.description }}
        </DialogDescription>
      </DialogHeader>

      <form v-if="row" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <FormField
          v-for="cell in row.languages"
          :id="`translation-${cell.language}`"
          :key="cell.language"
          :label="cell.language.toUpperCase()"
          :hint="hint(cell)"
          :error="errorOf(cell.language)"
          v-slot="{ invalid, describedBy }"
        >
          <Textarea
            :id="`translation-${cell.language}`"
            v-model="form.values[cell.language]"
            rows="3"
            maxlength="5000"
            :placeholder="fallbackOf(cell)"
            :aria-invalid="invalid"
            :aria-describedby="describedBy"
          />
        </FormField>

        <p v-if="form.errors.values" role="alert" class="text-destructive text-[12.5px]">{{ form.errors.values }}</p>

        <DialogFooter>
          <Button type="button" variant="ghost" @click="open = false">{{ common.cancel }}</Button>
          <Button type="submit" :disabled="form.processing">{{ form.processing ? common.saving : common.save }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
