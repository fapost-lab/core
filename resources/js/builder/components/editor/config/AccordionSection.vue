<script setup lang="ts">
import {ref} from 'vue'

const props = defineProps({
    title:       { type: String,                  required: true },
    badge:       { type: [String, Number, null],  default: null },
    defaultOpen: { type: Boolean,                 default: false },
})

const open = ref(props.defaultOpen)
</script>

<template>
    <div class="acc-section">
        <button type="button" class="acc-header" @click="open = !open">
            <span>{{ title }}</span>
            <span v-if="badge !== null && badge !== ''" class="acc-badge">{{ badge }}</span>
            <span class="acc-chevron" :class="{ 'acc-chevron--open': open }">›</span>
        </button>
        <div v-show="open" class="acc-body">
            <slot />
        </div>
    </div>
</template>
