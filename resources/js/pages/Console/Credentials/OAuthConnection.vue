<script setup lang="ts">
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { Check, Copy } from '@lucide/vue'
import { StatusDot } from '@fapost/ui/components/badge'
import { Button } from '@fapost/ui/components/button'
import { ConfirmDialog } from '@fapost/ui/components/confirm-dialog'
import { FormSection } from '@fapost/ui/components/form-section'
import { Input } from '@fapost/ui/components/input'
import { interpolate } from '@console/shell'
import type { CredentialsPageProps, OAuthConnectionState } from './types'

/**
 * The connection of an authorization-code credential: its status, the callback URL to register at the provider, and
 * the connect and disconnect actions. Connecting is a POST the server answers with an Inertia external location to the
 * provider, so there is nothing else to do here; the callback lands back on the server, which flashes the outcome.
 */
const props = defineProps<{ oauth: OAuthConnectionState }>()

const page = usePage<CredentialsPageProps>()
const t = computed(() => page.props.translations.console.credentials)
const o = computed(() => t.value.oauth)
const common = computed(() => page.props.translations.console.form)

const TONES = { connected: 'success', reconnect_needed: 'warning', not_connected: 'neutral' } as const satisfies Record<OAuthConnectionState['status'], string>

const hasGrant = computed(() => props.oauth.status !== 'not_connected')

function formatDate(iso: string): string {
  return new Intl.DateTimeFormat(page.props.locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))
}

const connectedLine = computed(() => {
  if (props.oauth.status !== 'connected' || !props.oauth.connected_at) {
    return ''
  }

  return props.oauth.connected_by_name
    ? interpolate(o.value.connected_by, { date: formatDate(props.oauth.connected_at), name: props.oauth.connected_by_name })
    : interpolate(o.value.connected_at, { date: formatDate(props.oauth.connected_at) })
})

const copied = ref(false)

async function copyCallback(): Promise<void> {
  try {
    await navigator.clipboard.writeText(props.oauth.callback_url)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch {
    // The URL is in a read-only field for the user to select by hand.
  }
}

const busy = ref(false)
const disconnectOpen = ref(false)

function connect(): void {
  router.post(props.oauth.urls.connect, {}, { onStart: () => (busy.value = true), onFinish: () => (busy.value = false) })
}

function disconnect(): void {
  router.delete(props.oauth.urls.disconnect, { preserveScroll: true })
}
</script>

<template>
  <FormSection :title="o.title" :description="o.description">
    <div class="flex flex-col gap-1.5" data-testid="oauth-status">
      <div class="flex items-center gap-2">
        <StatusDot :tone="TONES[oauth.status]">{{ o.statuses[oauth.status] }}</StatusDot>
        <span v-if="connectedLine" class="text-muted-foreground text-[13px]">{{ connectedLine }}</span>
      </div>
      <p v-if="oauth.status === 'connected' && oauth.refreshed_at" class="text-muted-foreground text-[13px]">
        {{ interpolate(o.refreshed, { date: formatDate(oauth.refreshed_at) }) }}
      </p>
      <p v-if="oauth.status === 'reconnect_needed'" class="text-[13px]" data-testid="oauth-failure">
        {{ o.reconnect_hint }}
        <span v-if="oauth.failure_code" class="text-muted-foreground">{{ interpolate(o.failure, { code: oauth.failure_code }) }}</span>
      </p>
    </div>

    <div class="flex flex-col gap-1.5">
      <label for="oauth-callback-url" class="text-sm font-medium">{{ o.callback_url }}</label>
      <div class="flex items-center gap-1">
        <Input id="oauth-callback-url" :model-value="oauth.callback_url" readonly aria-describedby="oauth-callback-hint" @focus="($event.target as HTMLInputElement).select()" />
        <Button type="button" variant="ghost" size="icon-sm" :aria-label="copied ? o.copied : o.copy" :title="copied ? o.copied : o.copy" @click="copyCallback">
          <Check v-if="copied" aria-hidden="true" />
          <Copy v-else aria-hidden="true" />
        </Button>
      </div>
      <p id="oauth-callback-hint" class="text-muted-foreground text-[13px]">{{ o.callback_hint }}</p>
    </div>

    <p class="text-muted-foreground text-[13px]">{{ o.params_hint }}</p>
    <p class="text-muted-foreground text-[13px]">{{ o.save_first }}</p>

    <div class="flex items-center gap-2">
      <Button type="button" data-testid="oauth-connect" :disabled="busy" @click="connect">{{ hasGrant ? o.reconnect : o.connect }}</Button>
      <Button v-if="hasGrant" type="button" variant="outline" data-testid="oauth-disconnect" @click="disconnectOpen = true">{{ o.disconnect }}</Button>
    </div>

    <ConfirmDialog
      v-model:open="disconnectOpen"
      :title="o.disconnect_confirm.title"
      :description="o.disconnect_confirm.description"
      :confirm-label="o.disconnect"
      :cancel-label="common.cancel"
      @confirm="disconnect"
    />
  </FormSection>
</template>
