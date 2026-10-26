<script setup lang="ts">
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { Check, Copy } from '@lucide/vue'
import { Button } from '@fapost/ui/components/button'
import { FormSection } from '@fapost/ui/components/form-section'
import { Input } from '@fapost/ui/components/input'
import type { ChannelsPageProps, SetupStep } from './types'

/**
 * What a person does on the provider's side to connect the channel: numbered steps, each with an optional value to
 * copy (the webhook URL, a manifest). Renders nothing for an integration without a guide.
 */
defineProps<{ steps: SetupStep[] }>()

const t = computed(() => usePage<ChannelsPageProps>().props.translations.console.channels)

const copiedIndex = ref<number | null>(null)

async function copy(value: string, index: number): Promise<void> {
  try {
    await navigator.clipboard.writeText(value)
    copiedIndex.value = index
    setTimeout(() => {
      if (copiedIndex.value === index) {
        copiedIndex.value = null
      }
    }, 2000)
  } catch {
    // The value stays on screen for the user to select by hand.
  }
}
</script>

<template>
  <FormSection v-if="steps.length > 0" :title="t.setup.title" :description="t.setup.description">
    <ol class="flex flex-col gap-4">
      <li v-for="(step, index) in steps" :key="index" class="flex flex-col gap-2">
        <span class="text-[13px] leading-snug font-semibold">{{ index + 1 }}. {{ step.title }}</span>
        <p v-if="step.text" class="text-muted-foreground text-sm whitespace-pre-line">{{ step.text }}</p>

        <div v-if="step.copy" class="flex items-start gap-2">
          <pre v-if="step.copyKind === 'code'" class="bg-muted max-h-64 min-w-0 flex-1 overflow-auto rounded-md p-3 font-mono text-xs" tabindex="0">{{ step.copy }}</pre>
          <Input v-else :model-value="step.copy" readonly class="font-mono" :aria-label="step.title" />
          <Button type="button" variant="outline" class="shrink-0" @click="copy(step.copy, index)">
            <Check v-if="copiedIndex === index" aria-hidden="true" />
            <Copy v-else aria-hidden="true" />
            {{ copiedIndex === index ? t.fields.copied : t.fields.copy }}
          </Button>
        </div>
      </li>
    </ol>
  </FormSection>
</template>
