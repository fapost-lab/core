<script setup lang="ts">
import { computed, watch } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'
import { Button } from '@fapost/ui/components/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@fapost/ui/components/dialog'
import { FormField } from '@fapost/ui/components/form-field'
import { Input } from '@fapost/ui/components/input'
import { OptionsPicker } from '@fapost/ui/components/options-picker'
import { interpolate } from '@console/shell'
import type { ApiTokensPageProps, ApiTokenRow, GrantablePermission } from './types'

/**
 * The form that issues a token (`row` is null) or rotates one (`row` is the token). Issuing picks a name, the
 * permissions the user may grant and a lifetime; rotating keeps the name and offers only the abilities the token
 * already has, since a rotation can keep or drop abilities but never add one. The server decides everything and answers
 * a refusal as errors on the field; on success the page reveals the new token.
 */
const props = defineProps<{
  row: ApiTokenRow | null
  grantable: GrantablePermission[]
  lifetime: { default: number; max: number }
  storeUrl: string
}>()

const open = defineModel<boolean>('open', { default: false })

const page = usePage<ApiTokensPageProps>()
const t = computed(() => page.props.translations.console.api_tokens)
const common = computed(() => page.props.translations.console.form)

const form = useForm<{ name: string; abilities: string[]; expires_in_days: number }>({
  name: '',
  abilities: [],
  expires_in_days: props.lifetime.default,
})

const rotating = computed(() => props.row !== null)

const options = computed(() => {
  const offered = props.row === null ? props.grantable.map((item) => item.value) : props.row.abilities.map((item) => item.value)
  const labels = new Map<string, { label: string; sensitive: boolean }>()

  for (const item of props.grantable) {
    labels.set(item.value, item)
  }

  for (const item of props.row?.abilities ?? []) {
    labels.set(item.value, item)
  }

  return offered.map((value) => {
    const item = labels.get(value)

    return { value, label: item ? (item.sensitive ? `${item.label} · ${t.value.sensitive}` : item.label) : value }
  })
})

watch(open, (isOpen) => {
  if (isOpen) {
    form.reset()
    form.clearErrors()
    form.abilities = props.row?.abilities.map((item) => item.value) ?? []
    form.expires_in_days = props.lifetime.default
    form.name = props.row?.name ?? ''
  }
})

function submit(): void {
  const url = props.row === null ? props.storeUrl : props.row.rotateUrl

  form
    .transform((data) => (props.row === null ? data : { abilities: data.abilities, expires_in_days: data.expires_in_days }))
    .post(url, { preserveScroll: true, onSuccess: () => (open.value = false) })
}
</script>

<template>
  <Dialog v-model:open="open">
    <DialogContent class="sm:max-w-lg">
      <DialogHeader>
        <DialogTitle>{{ rotating ? t.rotate.title : t.issue.title }}</DialogTitle>
        <DialogDescription>{{ rotating ? interpolate(t.rotate.description, { name: row?.name ?? '' }) : t.issue.description }}</DialogDescription>
      </DialogHeader>

      <form class="flex flex-col gap-5" novalidate @submit.prevent="submit">
        <FormField v-if="!rotating" id="token-name" :label="t.issue.name" :error="form.errors.name" v-slot="{ invalid, describedBy }">
          <Input id="token-name" v-model="form.name" name="name" required maxlength="120" autocomplete="off" :aria-invalid="invalid" :aria-describedby="describedBy" />
        </FormField>

        <FormField
          id="token-abilities"
          :label="t.issue.abilities"
          :hint="rotating ? t.rotate.abilities_hint : t.issue.abilities_hint"
          :error="form.errors.abilities || (form.errors as Record<string, string | undefined>).token"
          v-slot="{ invalid, describedBy }"
        >
          <OptionsPicker
            id="token-abilities"
            v-model="form.abilities"
            :options="options"
            :placeholder="t.issue.abilities_placeholder"
            :search-label="t.issue.search"
            :empty-label="t.issue.nothing_found"
            :selected-label="(count) => interpolate(t.issue.abilities_selected, { count })"
            :remove-label="(label) => `${t.issue.remove}: ${label}`"
            :invalid="invalid"
            :described-by="describedBy"
          />
        </FormField>

        <FormField
          id="token-expires"
          :label="t.issue.expires"
          :hint="interpolate(t.issue.expires_hint, { max: lifetime.max })"
          :error="form.errors.expires_in_days"
          v-slot="{ invalid, describedBy }"
        >
          <Input
            id="token-expires"
            v-model.number="form.expires_in_days"
            name="expires_in_days"
            type="number"
            min="1"
            :max="lifetime.max"
            required
            :aria-invalid="invalid"
            :aria-describedby="describedBy"
          />
        </FormField>

        <DialogFooter>
          <Button type="button" variant="ghost" @click="open = false">{{ common.cancel }}</Button>
          <Button type="submit" :disabled="form.processing">{{ rotating ? t.rotate.submit : t.issue.submit }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
