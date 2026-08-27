<script setup lang="ts">
import {ref} from 'vue'
import {sectionIconPath} from './sectionIcons'

const props = defineProps({
    title:       { type: String,                  required: true },
    badge:       { type: [String, Number, null],  default: null },
    icon:        { type: [String, null],          default: null },
    defaultOpen: { type: Boolean,                 default: false },
})

const open = ref(props.defaultOpen)
</script>

<template>
    <div class="acc-section" :class="{ 'acc-section--open': open }">
        <button type="button" class="acc-header" @click="open = !open">
            <svg
                v-if="icon"
                class="acc-icon"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.6"
                stroke-linecap="round"
                stroke-linejoin="round"
                aria-hidden="true"
            >
                <path :d="sectionIconPath(icon)" />
            </svg>
            <span class="acc-title">{{ title }}</span>
            <span v-if="badge !== null && badge !== ''" class="acc-badge">{{ badge }}</span>
            <span class="acc-chevron" :class="{ 'acc-chevron--open': open }">›</span>
        </button>
        <div v-show="open" class="acc-body">
            <slot />
        </div>
    </div>
</template>

<style scoped>
.acc-icon {
    width: 14px;
    height: 14px;
    flex-shrink: 0;
    color: var(--text-2, currentColor);
    margin-right: 6px;
}
.acc-title {
    flex: 1;
    text-align: left;
}
</style>
