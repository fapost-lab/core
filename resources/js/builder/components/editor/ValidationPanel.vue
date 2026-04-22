<script setup>
import { useBuilderStore } from '@builder/store/builderStore';
import { useSelectionStore } from '@builder/store/selectionStore';
import { useValidation } from '@builder/composables/useValidation';

const store = useBuilderStore();
const selectionStore = useSelectionStore();
const { validate } = useValidation();

function jumpToNode(error) {
    const nodeId = error?.path?.split('.')?.[1];
    if (!nodeId) {
        return;
    }

    selectionStore.select(nodeId);

    const target = document.getElementById(`node-card-${nodeId}`);
    if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}
</script>

<template>
    <Transition name="slide-up">
        <div
            v-if="store.validationOpen"
            class="fixed bottom-0 left-0 right-0 z-40 border-t border-gray-200 bg-white shadow-lg"
        >
            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-3">
                <div class="flex items-center gap-3">
                    <span class="text-sm font-medium text-gray-700">Validation</span>
                    <span
                        v-if="store.validationResult"
                        class="rounded-full px-2 py-0.5 text-xs"
                        :class="store.validationResult.valid
                            ? 'bg-green-100 text-green-600'
                            : 'bg-red-100 text-red-600'"
                    >
                        {{ store.validationResult.valid ? 'Valid' : `${store.validationResult.errors.length} errors` }}
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        class="rounded border border-gray-200 px-2 py-1 text-xs text-gray-500 hover:bg-gray-50"
                        @click="validate"
                    >
                        Revalidate
                    </button>
                    <button
                        class="text-sm text-gray-400 hover:text-gray-600"
                        @click="store.closeValidation()"
                    >
                        ✕
                    </button>
                </div>
            </div>

            <div class="max-h-48 overflow-y-auto px-6 py-3">
                <div v-if="store.validationResult?.valid" class="text-sm text-green-600">
                    No errors found. Ready to publish.
                </div>

                <div v-else-if="store.validationResult?.errors?.length" class="flex flex-col gap-2">
                    <button
                        v-for="error in store.validationResult.errors"
                        :key="`${error.path}:${error.message}`"
                        class="flex w-full items-start gap-3 rounded px-2 py-1.5 text-left text-sm transition-colors hover:bg-gray-50"
                        @click="jumpToNode(error)"
                    >
                        <span class="mt-0.5 shrink-0 text-red-400">●</span>
                        <div>
                            <code class="font-mono text-xs text-gray-400">{{ error.path }}</code>
                            <div class="text-gray-600">{{ error.message }}</div>
                        </div>
                    </button>
                </div>

                <div v-else class="text-sm text-gray-400">
                    Run validation to check your flow.
                </div>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.slide-up-enter-active,
.slide-up-leave-active {
    transition: transform 0.2s ease;
}

.slide-up-enter-from,
.slide-up-leave-to {
    transform: translateY(100%);
}
</style>
