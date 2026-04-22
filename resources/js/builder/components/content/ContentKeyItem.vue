<script setup>
defineProps({
    contentKey:        { type: String,  required: true },
    translationCount:  { type: Number,  default: 0 },
    totalLanguages:    { type: Number,  default: 0 },
    isSelected:        { type: Boolean, default: false },
})

const emit = defineEmits(['select'])
</script>

<template>
    <div
        class="key-item"
        :class="{ active: isSelected }"
        @click="emit('select', contentKey)"
    >
        <span class="key-name">{{ contentKey }}</span>
        <span
            v-if="totalLanguages > 0"
            class="count-badge"
            :class="{
                complete: translationCount === totalLanguages,
                partial:  translationCount > 0 && translationCount < totalLanguages,
                empty:    translationCount === 0,
            }"
        >
            {{ translationCount }}
        </span>
    </div>
</template>

<style scoped>
.key-item {
    padding: 7px 12px;
    font-size: 12.5px;
    font-family: 'DM Mono', monospace;
    color: var(--text-2);
    cursor: pointer;
    border-radius: 6px;
    margin: 1px 6px;
    transition: all .12s;
    display: flex;
    align-items: center;
    gap: 6px;
}
.key-item:hover { background: var(--surface-2); color: var(--text); }
.key-item.active { background: var(--primary-bg); color: var(--primary); }
.key-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.count-badge {
    font-size: 10px;
    padding: 1px 5px;
    border-radius: 3px;
    font-family: 'DM Sans', sans-serif;
    font-weight: 500;
    flex-shrink: 0;
}
.count-badge.complete { background: var(--sage-bg);  color: var(--sage); }
.count-badge.partial  { background: var(--amber-bg); color: var(--amber); }
.count-badge.empty    { background: var(--rose-bg);  color: var(--rose); }
</style>
