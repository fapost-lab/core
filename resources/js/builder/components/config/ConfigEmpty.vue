<template>
    <div class="config-empty">
        <div class="icon">☰</div>
        <div class="text">Select a node to<br>configure it</div>
    </div>
</template>

<style scoped>
.config-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: var(--text-3);
    padding: 20px;
    text-align: center;
}
.icon { font-size: 28px; opacity: .4; }
.text { font-size: 12.5px; line-height: 1.6; }
</style>
