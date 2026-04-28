import { onMounted, onUnmounted, ref } from 'vue'

const MIN_WIDTH = 240
const MAX_WIDTH = 800
const CONFIG_RATIO = 0.4

export function useConfigResize() {
    const width = ref(Math.round(window.innerWidth * CONFIG_RATIO))
    const isCollapsed = ref(false)
    const handleRef = ref(null)

    let dragging = false
    let startX = 0
    let startWidth = 0

    function onMouseMove(e) {
        if (!dragging) return
        const delta = startX - e.clientX
        width.value = Math.min(MAX_WIDTH, Math.max(MIN_WIDTH, startWidth + delta))
    }

    function onMouseUp() {
        if (!dragging) return
        dragging = false
        document.body.style.cursor = ''
        document.body.style.userSelect = ''
        document.removeEventListener('mousemove', onMouseMove)
        document.removeEventListener('mouseup', onMouseUp)
    }

    function onMouseDown(e) {
        dragging = true
        startX = e.clientX
        startWidth = width.value
        document.body.style.cursor = 'col-resize'
        document.body.style.userSelect = 'none'
        document.addEventListener('mousemove', onMouseMove)
        document.addEventListener('mouseup', onMouseUp)
        e.preventDefault()
    }

    function toggle() {
        isCollapsed.value = !isCollapsed.value
        if (!isCollapsed.value && width.value < MIN_WIDTH) {
            width.value = Math.round(window.innerWidth * CONFIG_RATIO)
        }
    }

    onMounted(() => {
        if (handleRef.value) {
            handleRef.value.addEventListener('mousedown', onMouseDown)
        }
    })

    onUnmounted(() => {
        if (handleRef.value) {
            handleRef.value.removeEventListener('mousedown', onMouseDown)
        }
        document.removeEventListener('mousemove', onMouseMove)
        document.removeEventListener('mouseup', onMouseUp)
    })

    return { width, isCollapsed, handleRef, toggle }
}
