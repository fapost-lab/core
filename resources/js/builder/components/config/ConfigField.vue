<script setup lang="ts">
import {computed} from 'vue'

const props = defineProps({
    fieldKey:   { type: String, required: true },
    schema:     { type: Object as () => Record<string, unknown>, required: true },
    modelValue: { default: undefined },
})

const emit = defineEmits(['update:modelValue'])

const val = computed({
    get: () => props.modelValue ?? (props.schema as Record<string, unknown>).default ?? '',
    set: (v) => emit('update:modelValue', v),
})

function addItem() {
    const arr: unknown[] = Array.isArray(props.modelValue) ? [...(props.modelValue as unknown[])] : []
    arr.push('')
    emit('update:modelValue', arr)
}

function removeItem(i: number) {
    const arr: unknown[] = Array.isArray(props.modelValue) ? [...(props.modelValue as unknown[])] : []
    arr.splice(i, 1)
    emit('update:modelValue', arr)
}

function updateItem(i: number, v: unknown) {
    const arr: unknown[] = Array.isArray(props.modelValue) ? [...(props.modelValue as unknown[])] : []
    arr[i] = v
    emit('update:modelValue', arr)
}

function onInputText(e: Event) {
    val.value = (e.target as HTMLInputElement).value
}

function onInputNumber(e: Event) {
    val.value = Number((e.target as HTMLInputElement).value)
}

function onChangeSelect(e: Event) {
    val.value = (e.target as HTMLSelectElement).value
}

function onUpdateItem(i: number, e: Event) {
    updateItem(i, (e.target as HTMLInputElement).value)
}
</script>

<template>
    <div class="config-field">
        <div class="field-label">
            {{ schema.label }}
            <span v-if="schema.required" class="req">*</span>
        </div>

        <!-- text / string -->
        <template v-if="schema.type === 'text'">
            <textarea
                class="field-input"
                :placeholder="(schema.placeholder as string) ?? ''"
                :value="val as string"
                @input="onInputText"
            />
        </template>

        <template v-else-if="schema.type === 'string' || schema.type === 'state-picker'">
            <input
                type="text"
                class="field-input"
                :class="{ mono: schema.type === 'state-picker' }"
                :placeholder="(schema.placeholder as string) ?? ''"
                :value="val as string"
                @input="onInputText"
            />
        </template>

        <template v-else-if="schema.type === 'number'">
            <input
                type="number"
                class="field-input"
                style="width:120px"
                :placeholder="(schema.placeholder as string) ?? ''"
                :value="val as number"
                @input="onInputNumber"
            />
        </template>

        <template v-else-if="schema.type === 'enum'">
            <select class="field-input" :value="val as string" @change="onChangeSelect">
                <option
                    v-for="opt in (schema.options as string[])"
                    :key="opt"
                    :value="opt"
                >
                    {{ opt }}
                </option>
            </select>
        </template>

        <!-- array of strings -->
        <template v-else-if="schema.type === 'array'">
            <div v-for="(item, i) in (Array.isArray(modelValue) ? modelValue as unknown[] : [])" :key="i" class="array-row">
                <input
                    type="text"
                    class="field-input"
                    :value="item as string"
                    @input="onUpdateItem(i, $event)"
                />
                <button type="button" class="del-btn" @click="removeItem(i)">×</button>
            </div>
            <button type="button" class="add-btn" @click="addItem">+ Add</button>
        </template>

        <!-- repeater (generic — rows are strings; specialised repeaters live in NodeConfig/*) -->
        <template v-else-if="schema.type === 'repeater'">
            <div v-for="(item, i) in (Array.isArray(modelValue) ? modelValue as unknown[] : [])" :key="i" class="array-row">
                <input
                    type="text"
                    class="field-input"
                    :value="typeof item === 'object' ? JSON.stringify(item) : item as string"
                    @input="onUpdateItem(i, $event)"
                />
                <button type="button" class="del-btn" @click="removeItem(i)">×</button>
            </div>
            <button type="button" class="add-btn" @click="addItem">+ Add</button>
        </template>

        <template v-else>
            <input
                type="text"
                class="field-input"
                :value="val as string"
                @input="onInputText"
            />
        </template>
    </div>
</template>

<style scoped>
.config-field { margin-bottom: 10px; }
.field-label {
    font-size: 12px;
    color: var(--text-2);
    margin-bottom: 4px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 3px;
}
.req { color: var(--rose); }
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
.mono { font-family: 'DM Mono', monospace; font-size: 12px; }

.array-row { display: flex; gap: 4px; margin-bottom: 4px; }
.del-btn {
    background: transparent; border: none; color: var(--text-3);
    cursor: pointer; font-size: 16px; padding: 0 4px;
    transition: color .12s;
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
