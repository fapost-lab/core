<script setup>
defineProps({
    beforeNodeId: { type: String, default: null },
    index: { type: Number, default: 0 },
})
</script>

<template>
    <div class="flex items-center justify-center w-96 py-1">
        <div class="flex items-center gap-2 text-gray-300 hover:text-gray-400 cursor-default">
            <div class="h-px flex-1 bg-gray-100" />
            <span class="text-xs">+ Add block</span>
            <div class="h-px flex-1 bg-gray-100" />
        </div>
    </div>
</template>
