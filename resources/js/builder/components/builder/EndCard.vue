<template>
    <div class="end-card">
        <div class="end-icon">■</div>
        <span>End of flow</span>
    </div>
</template>

<style scoped>
.end-card {
    width: 100%;
    max-width: 480px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 10px 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--text-3);
    font-size: 12.5px;
}
.end-icon {
    width: 24px;
    height: 24px;
    border-radius: 5px;
    background: var(--rose-bg);
    color: var(--rose);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    flex-shrink: 0;
}
</style>
