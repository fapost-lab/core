import { computed, ref } from 'vue'
import { fetchFormDefinition, submitForm } from '@tma/api/tmaApi'

export function useForm(formId) {
    const definition = ref(null)
    const answers = ref({})
    const loading = ref(true)
    const submitting = ref(false)
    const submitted = ref(false)
    const error = ref(null)

    async function load() {
        loading.value = true
        error.value = null

        try {
            definition.value = await fetchFormDefinition(formId)

            const nextAnswers = {}

            for (const field of definition.value.fields ?? []) {
                nextAnswers[field.id] = field.type === 'checkbox' ? false : ''
            }

            answers.value = nextAnswers
        } catch (e) {
            error.value = e.message
        } finally {
            loading.value = false
        }
    }

    async function submit() {
        submitting.value = true
        error.value = null

        try {
            await submitForm(formId, answers.value)
            submitted.value = true
        } catch (e) {
            error.value = e.message
        } finally {
            submitting.value = false
        }
    }

    const isValid = computed(() => {
        if (!definition.value) {
            return false
        }

        return definition.value.fields
            .filter((field) => field.required)
            .every((field) => {
                const value = answers.value[field.id]

                if (field.type === 'checkbox') {
                    return value === true
                }

                return value !== '' && value !== null && value !== undefined
            })
    })

    return {
        definition,
        answers,
        loading,
        submitting,
        submitted,
        error,
        isValid,
        load,
        submit,
    }
}
