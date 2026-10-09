<script setup lang="ts">
import {computed} from 'vue'

const props = defineProps({
    file: { type: Object, required: true },
    selected: { type: Boolean, default: false },
})

const emit = defineEmits(['select'])

const isImage = computed(() => props.file.preview?.kind === 'image')
const previewUrl = computed(() => props.file.preview?.signed_url ?? null)

/**
 * SVG paths for common heroicon names used in preview metadata.
 * @param {string} icon
 */
function iconPath(icon: string): string {
    const paths: Record<string, string> = {
        'document': 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
        'film': 'M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h1.5C5.496 19.5 6 18.996 6 18.375m-3.75.125v-10.5c0-.621.504-1.125 1.125-1.125h1.5c.621 0 1.125.504 1.125 1.125v10.5m0 0c0 .621.504 1.125 1.125 1.125h10.5c.621 0 1.125-.504 1.125-1.125m0-11.25V6.375c0-.621-.504-1.125-1.125-1.125H6.375A1.125 1.125 0 005.25 6.375v1.875M21 19.5h-1.5a1.125 1.125 0 01-1.125-1.125V9m0 10.5c0 .621-.504 1.125-1.125 1.125',
        'musical-note': 'M9 9l10.5-3m0 6.553v3.75a2.25 2.25 0 01-1.632 2.163l-1.32.377a1.803 1.803 0 11-.99-3.467l2.31-.66a2.25 2.25 0 001.632-2.163zm0 0V2.25L9 5.25v10.303m0 0v3.75a2.25 2.25 0 01-1.632 2.163l-1.32.377a1.803 1.803 0 01-.99-3.467l2.31-.66A2.25 2.25 0 009 15.553z',
        'photo': 'M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z',
        'face-smile': 'M15.182 15.182a4.5 4.5 0 01-6.364 0M21 12a9 9 0 11-18 0 9 9 0 0118 0zM9.75 9.75c0 .414-.168.75-.375.75S9 10.164 9 9.75 9.168 9 9.375 9s.375.336.375.75zm-.375 0h.008v.015h-.008V9.75zm5.625 0c0 .414-.168.75-.375.75s-.375-.336-.375-.75.168-.75.375-.75.375.336.375.75zm-.375 0h.008v.015h-.008V9.75z',
    }

    return paths[icon] ?? paths['document']
}
</script>

<template>
    <button
        type="button"
        class="mfc-card"
        :class="{ 'mfc-card--selected': selected }"
        :title="file.name"
        @click="emit('select', file)"
    >
        <div class="mfc-thumb">
            <img
                v-if="isImage && previewUrl"
                :src="previewUrl"
                :alt="file.name"
                class="mfc-img"
                loading="lazy"
            />
            <svg
                v-else
                class="mfc-icon"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <path :d="iconPath(file.preview?.icon_heroicon)" />
            </svg>
        </div>

        <div class="mfc-name">{{ file.name }}</div>

        <div v-if="selected" class="mfc-check" aria-hidden="true">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 6L9 17l-5-5" />
            </svg>
        </div>
    </button>
</template>

<style scoped>
.mfc-card {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 8px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    background: var(--surface);
    cursor: pointer;
    text-align: left;
    transition: border-color .12s, box-shadow .12s, background .12s;
    width: 100%;
    overflow: hidden;
}
.mfc-card:hover { border-color: var(--primary); background: var(--primary-bg); }
.mfc-card--selected { border-color: var(--primary); background: var(--primary-bg); box-shadow: 0 0 0 2px color-mix(in srgb, var(--primary) 30%, transparent); }

.mfc-thumb {
    width: 100%;
    aspect-ratio: 4/3;
    border-radius: 5px;
    overflow: hidden;
    background: var(--surface-2);
    display: flex;
    align-items: center;
    justify-content: center;
}

.mfc-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.mfc-icon {
    width: 28px;
    height: 28px;
    color: var(--text-3);
}

.mfc-name {
    font-size: 11px;
    font-weight: 500;
    color: var(--text-2);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    line-height: 1.3;
}

.mfc-check {
    position: absolute;
    top: 6px;
    right: 6px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--on-solid);
    box-shadow: var(--shadow-chip);
}
</style>
