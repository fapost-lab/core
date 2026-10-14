import { defineComponent, h } from 'vue'
import { Badge } from '@fapost/ui/components/badge'

export default defineComponent({
    name: 'ShowcaseStats',
    props: { recordId: { type: String, default: null }, assistantId: { type: String, default: null } },
    setup(props) {
        return () => h('div', { class: 'showcase-panel', 'data-showcase': 'stats' }, [
            h(Badge, { variant: 'secondary' }, () => 'Stats'),
            props.recordId ? h('span', { class: 'showcase-mark' }, props.recordId) : null,
        ])
    },
})
