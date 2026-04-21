<script setup>
defineProps({
    flow_id: {
        type: String,
        required: true,
    },
    name: {
        type: String,
        required: true,
    },
    draft_version: {
        type: Number,
        required: true,
    },
    published_version: {
        type: Number,
        default: null,
    },
    definition: {
        type: Object,
        required: true,
    },
    published_at: {
        type: String,
        default: null,
    },
});
</script>

<template>
    <main class="mx-auto max-w-5xl p-6">
        <header class="mb-6">
            <h1 class="text-2xl font-semibold">{{ name }}</h1>
            <p class="text-sm text-gray-500">Flow ID: {{ flow_id }}</p>
        </header>

        <section class="rounded-lg border p-4">
            <h2 class="mb-3 text-lg font-medium">Builder Payload</h2>
            <div class="space-y-2 text-sm">
                <p><strong>Draft version:</strong> {{ draft_version }}</p>
                <p><strong>Published version:</strong> {{ published_version ?? '—' }}</p>
                <p><strong>Published at:</strong> {{ published_at ?? '—' }}</p>
            </div>
            <pre class="mt-4 overflow-auto rounded bg-gray-100 p-3 text-xs">{{ definition }}</pre>
        </section>
    </main>
</template>
