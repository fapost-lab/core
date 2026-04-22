<script setup>
import { ref } from 'vue'
import ContentKeysPanel    from './ContentKeysPanel.vue'
import ContentEditorPanel  from './ContentEditorPanel.vue'
import ContentLanguagePanel from './ContentLanguagePanel.vue'

// Stub state — будет заменено contentStore в будущей задаче
const selectedKey  = ref(null)
const translations = ref({})

const keys = ref([])

const languages = ref([
    { code: 'uk', name: 'Ukrainian' },
    { code: 'en', name: 'English' },
])

function onSelectKey(key) {
    selectedKey.value = key
    // В реальной реализации загружаем переводы из API
}

function onUpdate(key, langCode, value) {
    if (!translations.value[key]) translations.value[key] = {}
    translations.value[key][langCode] = value
}

function onSave() {
    // PUT /builder/flows/{id}/content/{key}
}

function onDelete(key) {
    keys.value = keys.value.filter((k) => k.key !== key)
    if (selectedKey.value === key) selectedKey.value = null
}
</script>

<template>
    <div class="content-tab">
        <ContentKeysPanel
            :keys="keys"
            :selected-key="selectedKey"
            :languages="languages"
            @select="onSelectKey"
            @add-key="() => {}"
        />

        <ContentEditorPanel
            :content-key="selectedKey"
            :translations="selectedKey ? (translations[selectedKey] ?? {}) : {}"
            :languages="languages"
            @update="onUpdate"
            @save="onSave"
            @delete="onDelete"
        />

        <ContentLanguagePanel
            :languages="languages"
            @add-language="() => {}"
        />
    </div>
</template>

<style scoped>
.content-tab {
    display: flex;
    flex: 1;
    overflow: hidden;
}
</style>
