<template>
    <div class="seq-connector">
        <div class="conn-line"></div>
        <div class="conn-dot"></div>
        <div class="conn-line"></div>
    </div>
</template>

<style scoped>
.seq-connector {
    display: flex;
    flex-direction: column;
    align-items: center;
}
.conn-line {
    width: 1.5px;
    height: 16px;
    background: var(--border-2);
}
.conn-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    border: 1.5px solid var(--border-2);
    background: var(--bg);
}
</style>
