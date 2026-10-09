<script setup lang="ts">
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Eye, EyeOff, Sparkles } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { Input } from '@fapost/ui/components/input'
import { generateSecret } from './secret'
import type { ChannelsPageProps } from './types'

/**
 * A secret the user types, hidden until they ask to see it. When editing, the stored secret is not here (the server
 * never sends it), so the field starts empty and an empty field means "keep it".
 */
defineProps<{
  id: string
  name: string
  generatable?: boolean
  required?: boolean
  invalid?: boolean
  describedBy?: string
}>()

const model = defineModel<string>({ required: true })

const t = computed(() => usePage<ChannelsPageProps>().props.translations.console.channels.fields)
const revealed = ref(false)

function generate(): void {
  model.value = generateSecret()
  // A generated secret is worth seeing once: it is the only time it is shown before saving.
  revealed.value = true
}
</script>

<template>
  <div class="flex items-center gap-2">
    <Input
      :id="id"
      v-model="model"
      :name="name"
      :type="revealed ? 'text' : 'password'"
      :required="required"
      maxlength="65535"
      autocomplete="new-password"
      data-1p-ignore
      data-lpignore="true"
      data-form-type="other"
      spellcheck="false"
      :aria-invalid="invalid"
      :aria-describedby="describedBy"
    />
    <Button type="button" variant="outline" size="icon" class="shrink-0" :aria-label="revealed ? t.hide : t.show" :aria-pressed="revealed" @click="revealed = !revealed">
      <EyeOff v-if="revealed" aria-hidden="true" />
      <Eye v-else aria-hidden="true" />
    </Button>
    <Button v-if="generatable" type="button" variant="outline" class="shrink-0" @click="generate">
      <Sparkles aria-hidden="true" />
      {{ t.generate }}
    </Button>
  </div>
</template>
