<script setup>
import { useNavigationStore } from '@builder/store/navigationStore'

const nav = useNavigationStore()
</script>

<template>
    <div class="breadcrumb">
        <template v-for="(seg, i) in nav.breadcrumbs" :key="i">
            <span
                v-if="seg.index === -1"
                class="bc-link"
                @click="nav.navigateToRoot()"
            >
                Main flow
            </span>
            <template v-else-if="i < nav.breadcrumbs.length - 1">
                <span class="bc-sep">/</span>
                <span class="bc-link" @click="nav.navigateUp(Math.floor(seg.index))">
                    {{ seg.label }}
                </span>
            </template>
            <template v-else>
                <span class="bc-sep">/</span>
                <span class="bc-current">{{ seg.label }}</span>
            </template>
        </template>
    </div>
</template>

<style scoped>
.breadcrumb {
    align-self: flex-start;
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 11.5px;
    color: var(--text-3);
    margin-bottom: 16px;
    padding: 0 4px;
}
.bc-link {
    color: var(--text-3);
    cursor: pointer;
    text-decoration: none;
    transition: color .12s;
}
.bc-link:hover { color: var(--text-2); }
.bc-sep { color: var(--border-2); }
.bc-current { color: var(--text-2); font-weight: 500; }
</style>
