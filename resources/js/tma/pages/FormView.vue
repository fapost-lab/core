<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useTelegram } from '@tma/composables/useTelegram'
import { useForm } from '@tma/composables/useForm'
import TmaFormRenderer from '@tma/components/TmaFormRenderer.vue'

const route = useRoute()
const telegram = useTelegram()
const {
    definition,
    answers,
    loading,
    submitting,
    submitted,
    error,
    isValid,
    load,
    submit,
} = useForm(route.params.formId)

const hasAttemptedSubmit = ref(false)

const canSubmit = computed(() => !loading.value && !submitting.value && !submitted.value)

function hideMainButton() {
    telegram.hideMainButton()
}

onMounted(async () => {
    telegram.ready()
    telegram.expand()

    await load()

    telegram.showMainButton('Submit', async () => {
        if (submitting.value) {
            return
        }

        hasAttemptedSubmit.value = true

        if (!isValid.value) {
            telegram.showAlert('Please fill in all required fields.')
            return
        }

        await submit()

        if (submitted.value) {
            telegram.close()
        }
    })
})

onUnmounted(() => {
    hideMainButton()
})
</script>

<template>
    <div class="min-h-screen bg-white p-4">
        <div v-if="loading" class="flex h-40 items-center justify-center">
            <span class="text-sm text-gray-400">Loading...</span>
        </div>

        <div v-else-if="error" class="mt-8 text-center text-sm text-red-500">
            {{ error }}
        </div>

        <div v-else-if="submitted" class="mt-8 text-center">
            <div class="mb-2 text-2xl">OK</div>
            <div class="text-sm text-gray-600">Submitted successfully</div>
        </div>

        <template v-else-if="definition">
            <h1 class="mb-6 text-lg font-semibold text-gray-800">{{ definition.title }}</h1>
            <TmaFormRenderer
                v-model:answers="answers"
                :fields="definition.fields"
            />
            <p
                v-if="hasAttemptedSubmit && !isValid && canSubmit"
                class="mt-4 text-sm text-red-500"
            >
                Fill in all required fields to submit.
            </p>
        </template>
    </div>
</template>
