<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { Button } from '@fapost/ui/components/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { Input } from '@fapost/ui/components/input'
import { Label } from '@fapost/ui/components/label'

interface AuthTranslations {
  activation: {
    title: string
    heading: string
    description: string
    email: string
    password: string
    password_confirmation: string
    submit: string
  }
}

const props = defineProps<{ action: string; token: string; email: string }>()

const page = usePage<{ translations: { auth: AuthTranslations } }>()
const t = computed(() => page.props.translations.auth.activation)

const form = useForm({
  token: props.token,
  password: '',
  password_confirmation: '',
})

function submit(): void {
  form.post(props.action, {
    onFinish: () => form.reset('password', 'password_confirmation'),
  })
}
</script>

<template>
  <Head :title="t.title" />

  <main class="bg-background text-foreground flex min-h-screen items-center justify-center p-4">
    <Card class="w-full max-w-sm">
      <CardHeader>
        <CardTitle>{{ t.heading }}</CardTitle>
        <CardDescription>{{ t.description }}</CardDescription>
      </CardHeader>
      <CardContent>
        <form class="flex flex-col gap-4" @submit.prevent="submit">
          <p v-if="form.errors.token" class="text-destructive text-sm" role="alert">{{ form.errors.token }}</p>

          <div class="flex flex-col gap-2">
            <Label for="email">{{ t.email }}</Label>
            <!-- Read-only, for the password manager to store the new password under the right account. -->
            <Input id="email" :model-value="email" type="email" autocomplete="username" readonly />
          </div>

          <div class="flex flex-col gap-2">
            <Label for="password">{{ t.password }}</Label>
            <Input
              id="password"
              v-model="form.password"
              type="password"
              autocomplete="new-password"
              autofocus
              required
              :aria-invalid="form.errors.password ? true : undefined"
            />
            <p v-if="form.errors.password" class="text-destructive text-sm" role="alert">{{ form.errors.password }}</p>
          </div>

          <div class="flex flex-col gap-2">
            <Label for="password_confirmation">{{ t.password_confirmation }}</Label>
            <Input
              id="password_confirmation"
              v-model="form.password_confirmation"
              type="password"
              autocomplete="new-password"
              required
              :aria-invalid="form.errors.password_confirmation ? true : undefined"
            />
            <p v-if="form.errors.password_confirmation" class="text-destructive text-sm" role="alert">
              {{ form.errors.password_confirmation }}
            </p>
          </div>

          <Button type="submit" :disabled="form.processing">{{ t.submit }}</Button>
        </form>
      </CardContent>
    </Card>
  </main>
</template>
