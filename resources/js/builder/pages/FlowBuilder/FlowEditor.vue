<script setup>
import { onMounted } from 'vue';
import { useBuilderStore } from '@builder/store/builderStore';
import { useRegistryStore } from '@builder/store/registryStore';
import { useAutoSave } from '@builder/composables/useAutoSave';

const props = defineProps({
    flow: { type: Object, required: true },
});

const builderStore = useBuilderStore();
const registryStore = useRegistryStore();

builderStore.init(props.flow);
useAutoSave();

onMounted(async () => {
    await registryStore.load();
});
</script>

<template>
    <div class="flex h-screen flex-col">
        <header class="flex h-12 items-center gap-4 border-b bg-white px-4">
            <span class="font-medium">{{ builderStore.flowName }}</span>
            <span class="text-sm text-gray-400">v{{ flow.publishedVersion ?? '—' }}</span>
            <span class="ml-auto text-sm text-gray-400">{{ builderStore.saveStatus }}</span>
        </header>
        <main class="flex flex-1 overflow-hidden">
            <div class="flex flex-1 items-center justify-center text-gray-300">
                Editor placeholder
            </div>
        </main>
    </div>
</template>
