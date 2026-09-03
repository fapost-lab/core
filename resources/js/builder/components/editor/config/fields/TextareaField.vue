<script setup lang="ts">
import {computed, ref} from 'vue'
import VariablePicker from '../VariablePicker.vue'
import {useInsertAtCursor} from '@builder/composables/useInsertAtCursor'

const props = defineProps({
    value:  { type: String, default: '' },
    schema: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:value'])

const textareaRef = ref<HTMLTextAreaElement | null>(null)
const insert      = useInsertAtCursor(textareaRef, (next) => emit('update:value', next))

// Opt-out for fields whose text never reaches runtime (builder-only notes) —
// there `{{ ... }}` would never resolve, so the picker is hidden.
const showPicker = computed(
    () => (props.schema as { variable_picker?: boolean })?.variable_picker !== false,
)
</script>

<template>
    <div class="textarea-with-picker">
        <textarea
            ref="textareaRef"
            class="field-input"
            rows="4"
            :value="value ?? ''"
            :placeholder="(schema as { placeholder?: string })?.placeholder ?? ''"
            @input="emit('update:value', ($event.target as HTMLTextAreaElement).value)"
        />
        <VariablePicker
            v-if="showPicker"
            class="textarea-picker"
            @select="insert"
        />
    </div>
</template>

<style scoped>
.textarea-with-picker {
    position: relative;
}
.textarea-picker {
    position: absolute;
    top: 4px;
    right: 4px;
    z-index: 1;
}
</style>
