<script setup lang="ts">
import {nextTick, ref, useTemplateRef, watch} from 'vue'
import BaseModal from './BaseModal.vue'

const props = defineProps({
    open:            { type: Boolean, required: true },
    isReplyKeyboard: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'confirm'])

const chips = ref<string[]>([])
const inputValue = ref('')
const inputRef = useTemplateRef('inputRef')

watch(() => props.open, async (val) => {
    if (val) {
        chips.value = []
        inputValue.value = ''
        await nextTick()
        inputRef.value?.focus()
    }
})

function commitChip() {
    const text = inputValue.value.trim()
    if (text === '') return
    chips.value.push(text)
    inputValue.value = ''
}

function onInputKeydown(e: KeyboardEvent) {
    if (e.key === 'Enter' || e.key === ',') {
        e.preventDefault()
        commitChip()
        return
    }
    if (e.key === 'Backspace' && inputValue.value === '' && chips.value.length > 0) {
        chips.value.pop()
    }
}

function removeChip(index: number) {
    chips.value.splice(index, 1)
}

function confirm() {
    // Commit any pending text as a final chip before emitting.
    commitChip()
    if (chips.value.length === 0) return

    const newButtons = chips.value.map((label) => ({
        id:    crypto.randomUUID(),
        type:  props.isReplyKeyboard ? 'reply' : 'callback',
        label,
        value: '',
    }))
    emit('confirm', newButtons)
    emit('close')
}

function cancel() {
    emit('close')
}
</script>

<template>
    <BaseModal :open="open" title="Quick add buttons" width="460px" @close="cancel">
        <p class="qa-hint">
            Type a label and press <kbd>Enter</kbd> or <kbd>,</kbd> to create a chip.
            Each chip becomes a button.
        </p>

        <div class="qa-chips" @click="inputRef?.focus()">
            <span v-for="(chip, idx) in chips" :key="idx" class="qa-chip">
                {{ chip }}
                <button type="button" class="qa-chip-del" @click.stop="removeChip(idx)">×</button>
            </span>
            <input
                ref="inputRef"
                v-model="inputValue"
                type="text"
                class="qa-input"
                :placeholder="chips.length === 0 ? 'e.g. Yes, No, Maybe…' : ''"
                @keydown="onInputKeydown"
                @blur="commitChip"
            >
        </div>

        <template #footer>
            <button type="button" class="qa-btn" @click="cancel">Cancel</button>
            <button
                type="button"
                class="qa-btn qa-btn--primary"
                :disabled="chips.length === 0 && inputValue.trim() === ''"
                @click="confirm"
            >
                Add {{ chips.length + (inputValue.trim() === '' ? 0 : 1) }} button{{ (chips.length + (inputValue.trim() === '' ? 0 : 1)) === 1 ? '' : 's' }}
            </button>
        </template>
    </BaseModal>
</template>

<style scoped>
.qa-hint {
    margin: 0 0 10px;
    font-size: 12px;
    color: var(--text-2);
    line-height: 1.5;
}
.qa-hint kbd {
    display: inline-block;
    padding: 1px 5px;
    border: 1px solid var(--border-2);
    border-radius: 4px;
    background: var(--surface-2);
    font-family: inherit;
    font-size: 11px;
    color: var(--text);
}

.qa-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: center;
    min-height: 80px;
    padding: 8px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface-2);
    cursor: text;
}
.qa-chips:focus-within { border-color: var(--primary); background: var(--surface); }

.qa-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    background: var(--primary-bg);
    color: var(--primary);
    border-radius: 12px;
    font-size: 12.5px;
    line-height: 1.4;
}
.qa-chip-del {
    border: none;
    background: transparent;
    color: var(--primary);
    font-size: 14px;
    line-height: 1;
    cursor: pointer;
    padding: 0;
    opacity: .7;
}
.qa-chip-del:hover { opacity: 1; }

.qa-input {
    flex: 1;
    min-width: 80px;
    border: none;
    background: transparent;
    outline: none;
    font-family: inherit;
    font-size: 12.5px;
    color: var(--text);
    padding: 3px 4px;
}

.qa-btn {
    padding: 6px 14px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface);
    font-family: inherit;
    font-size: 12.5px;
    color: var(--text-2);
    cursor: pointer;
    transition: border-color .12s, background .12s, color .12s;
}
.qa-btn:hover { border-color: var(--border-2); color: var(--text); }
.qa-btn--primary {
    background: var(--primary);
    border-color: var(--primary);
    color: #fff;
    font-weight: 500;
}
.qa-btn--primary:hover { background: var(--primary); border-color: var(--primary); color: #fff; opacity: .9; }
.qa-btn--primary:disabled {
    opacity: .4;
    cursor: not-allowed;
}
</style>
