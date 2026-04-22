<script setup>
import { computed } from 'vue'

const props = defineProps({
    modelValue: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:modelValue'])

const textVal = computed({
    get: () => {
        const t = props.modelValue.text
        if (!t) return ''
        return typeof t === 'object' ? (Object.values(t)[0] ?? '') : String(t)
    },
    set: (v) => emit('update:modelValue', { ...props.modelValue, text: v }),
})

const buttons = computed(() => props.modelValue.buttons ?? [])

function addButton() {
    emit('update:modelValue', {
        ...props.modelValue,
        buttons: [...buttons.value, { label: '', payload: '' }],
    })
}

function updateButton(i, patch) {
    const updated = buttons.value.map((b, idx) => idx === i ? { ...b, ...patch } : b)
    emit('update:modelValue', { ...props.modelValue, buttons: updated })
}

function removeButton(i) {
    emit('update:modelValue', {
        ...props.modelValue,
        buttons: buttons.value.filter((_, idx) => idx !== i),
    })
}
</script>

<template>
    <!-- Text body -->
    <div class="config-section">
        <div class="config-label">Body</div>
        <div class="config-field">
            <div class="field-label">Message text</div>
            <textarea
                class="field-input"
                placeholder="Welcome, {{flow.name}}"
                :value="textVal"
                @input="textVal = $event.target.value"
            />
        </div>
    </div>

    <!-- Buttons -->
    <div class="config-section">
        <div class="config-label">Buttons</div>

        <div
            v-for="(btn, i) in buttons"
            :key="i"
            class="btn-row"
        >
            <input
                type="text"
                class="field-input"
                placeholder="Label"
                :value="btn.label"
                @input="updateButton(i, { label: $event.target.value })"
            />
            <input
                type="text"
                class="field-input"
                placeholder="Payload"
                :value="btn.payload"
                @input="updateButton(i, { payload: $event.target.value })"
            />
            <button type="button" class="del-btn" @click="removeButton(i)">×</button>
        </div>

        <button type="button" class="add-btn" @click="addButton">+ Add button</button>
    </div>
</template>

<style scoped>
.config-section { padding: 12px 0; border-bottom: 1px solid var(--border); }
.config-section:last-child { border-bottom: none; }
.config-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--text-3);
    margin-bottom: 7px;
}
.config-field { margin-bottom: 10px; }
.field-label { font-size: 12px; color: var(--text-2); margin-bottom: 4px; font-weight: 500; }
.field-input {
    width: 100%;
    padding: 6px 9px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface-2);
    font-family: 'DM Sans', sans-serif;
    font-size: 12.5px;
    color: var(--text);
    outline: none;
    resize: none;
    transition: border-color .15s;
}
.field-input:focus { border-color: var(--primary); background: #fff; }
textarea.field-input { min-height: 72px; line-height: 1.5; }

.btn-row { display: flex; gap: 4px; margin-bottom: 6px; align-items: center; }
.btn-row .field-input { flex: 1; }
.del-btn {
    background: transparent; border: none; color: var(--text-3);
    cursor: pointer; font-size: 16px; padding: 0 2px; transition: color .12s;
}
.del-btn:hover { color: var(--rose); }
.add-btn {
    width: 100%; padding: 5px;
    border: 1px dashed var(--border-2);
    border-radius: 6px; background: transparent;
    font-family: 'DM Sans', sans-serif;
    font-size: 12px; color: var(--text-3);
    cursor: pointer; transition: all .15s;
}
.add-btn:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-bg); }
</style>
