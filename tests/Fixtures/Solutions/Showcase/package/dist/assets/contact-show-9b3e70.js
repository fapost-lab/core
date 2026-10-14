import { defineComponent, h } from 'vue'

export default defineComponent({
    name: 'ShowcaseContact',
    props: { record: { type: String, required: true }, assistant: { type: String, required: true } },
    setup(props) {
        return () => h('section', { class: 'showcase-panel', 'data-showcase': 'contact' }, `Contact ${props.record}`)
    },
})
