import { defineComponent, h } from 'vue'
import { Badge } from '@fapost/ui/components/badge'

export default defineComponent({
    name: 'ShowcaseNote',
    props: { recordId: { type: String, default: null }, assistantId: { type: String, default: null } },
    setup(props) {
        return () => h('div', { class: 'showcase-panel', 'data-showcase': 'note' }, [
            h(Badge, { variant: 'secondary' }, () => 'Note'),
            props.recordId ? h('span', { class: 'showcase-mark' }, props.recordId) : null,
        ])
    },
})
