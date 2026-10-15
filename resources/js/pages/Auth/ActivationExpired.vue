<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import { Button } from '@fapost/ui/components/button'
import { Card, CardDescription, CardFooter, CardHeader, CardTitle } from '@fapost/ui/components/card'

interface AuthTranslations {
  activation: {
    expired: {
      title: string
      heading: string
      description: string
      back: string
    }
  }
}

defineProps<{ homeUrl: string }>()

const page = usePage<{ translations: { auth: AuthTranslations } }>()
const t = computed(() => page.props.translations.auth.activation.expired)
</script>

<template>
  <Head :title="t.title" />

  <main class="bg-background text-foreground flex min-h-screen items-center justify-center p-4">
    <Card class="w-full max-w-sm">
      <CardHeader>
        <CardTitle>{{ t.heading }}</CardTitle>
        <CardDescription>{{ t.description }}</CardDescription>
      </CardHeader>
      <CardFooter>
        <!-- A plain link: the address may be another app (the tenant root, the landing page), not an Inertia page. -->
        <Button as="a" :href="homeUrl" variant="outline">{{ t.back }}</Button>
      </CardFooter>
    </Card>
  </main>
</template>
