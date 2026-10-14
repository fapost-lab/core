import { defineComponent, h } from 'vue'
import { Badge } from '@fapost/ui/components/badge'

export default defineComponent({
    name: 'ShowcaseAssistantSettings',
    props: { recordId: { type: String, default: null }, assistantId: { type: String, default: null } },
    setup(props) {
        return () => h('div', { class: 'showcase-panel', 'data-showcase': 'settings-assistant' }, [
            h(Badge, { variant: 'secondary' }, () => 'Assistant settings'),
            props.recordId ? h('span', { class: 'showcase-mark' }, props.recordId) : null,
        ])
    },
})
