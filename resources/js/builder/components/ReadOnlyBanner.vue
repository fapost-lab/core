<script setup lang="ts">
import {computed} from 'vue'
import {usePage} from '@inertiajs/vue3'
import {useBuilderStore} from '@builder/store/builderStore'
import {useTranslations} from '@builder/composables/useTranslations'
import type {ReadOnlyNotice} from '@builder/dto/types'

/**
 * The strip above a view-only builder: the operator's notice for the stopped workspace. A 423 answer
 * carries a fresher notice than the page did when it loaded, so that one wins.
 */
const page         = usePage()
const builderStore = useBuilderStore()
const {t}          = useTranslations()

const notice = computed<ReadOnlyNotice | null>(() => {
    const shared = (page.props as {accessState?: {notice?: ReadOnlyNotice | null}}).accessState?.notice ?? null

    return builderStore.readOnlyNotice ?? shared
})
</script>

<template>
    <div v-if="builderStore.readOnly" role="status" class="read-only-banner" data-test="builder-read-only-banner">
        <template v-if="notice">
            <span class="read-only-title">{{ notice.title }}</span>
            <span v-if="notice.message">{{ notice.message }}</span>
            <a v-if="notice.actionUrl && notice.actionLabel" :href="notice.actionUrl" class="read-only-link">
                {{ notice.actionLabel }}
            </a>
        </template>
        <span v-else>{{ t('read_only.banner') }}</span>
    </div>
</template>

<style scoped>
.read-only-banner {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 12px;
    padding: 8px 16px;
    font-size: 13px;
    background: var(--rose-bg);
    color: var(--rose);
    border-bottom: 1px solid var(--rose-border);
    flex-shrink: 0;
}
.read-only-title { font-weight: 600; }
.read-only-link { color: inherit; text-decoration: underline; text-underline-offset: 3px; }
</style>
