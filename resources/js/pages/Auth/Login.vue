<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { Button } from '@fapost/ui/components/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@fapost/ui/components/card'
import { Checkbox } from '@fapost/ui/components/checkbox'
import { Input } from '@fapost/ui/components/input'
import { Label } from '@fapost/ui/components/label'

interface AuthTranslations {
  login: {
    title: string
    heading: string
    description: string
    email: string
    password: string
    remember: string
    submit: string
  }
}

const props = defineProps<{ action: string }>()

const page = usePage<{ translations: { auth: AuthTranslations } }>()
const t = computed(() => page.props.translations.auth.login)

const form = useForm({
  email: '',
  password: '',
  remember: false,
})

function submit(): void {
  form.post(props.action, {
    onFinish: () => form.reset('password'),
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
          <div class="flex flex-col gap-2">
            <Label for="email">{{ t.email }}</Label>
            <Input
              id="email"
              v-model="form.email"
              type="email"
              autocomplete="username"
              autofocus
              required
              :aria-invalid="form.errors.email ? true : undefined"
            />
            <p v-if="form.errors.email" class="text-destructive text-sm" role="alert">{{ form.errors.email }}</p>
          </div>

          <div class="flex flex-col gap-2">
            <Label for="password">{{ t.password }}</Label>
            <Input
              id="password"
              v-model="form.password"
              type="password"
              autocomplete="current-password"
              required
              :aria-invalid="form.errors.password ? true : undefined"
            />
            <p v-if="form.errors.password" class="text-destructive text-sm" role="alert">{{ form.errors.password }}</p>
          </div>

          <div class="flex items-center gap-2">
            <Checkbox id="remember" v-model="form.remember" />
            <Label for="remember">{{ t.remember }}</Label>
          </div>

          <Button type="submit" :disabled="form.processing">{{ t.submit }}</Button>
        </form>
      </CardContent>
    </Card>
  </main>
</template>
