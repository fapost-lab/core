<script setup lang="ts">
import { computed } from 'vue'
import { cn } from '@fapost/ui/lib/utils'
import SolutionMount from './SolutionMount.vue'
import { widgetGridClass, widgetSpanClass, type WidgetColumns, type WidgetTarget } from './extensions'
import { useExtensions } from './useExtensions'

/**
 * The widgets Solutions declared on a dashboard, in a grid with the dashboard's own columns; a widget's size is its
 * span. A widget fetches its own data from its Solution's routes, never through the dashboard's request. Nothing is
 * drawn when no Solution declared a widget here.
 */
const props = defineProps<{ target: WidgetTarget; columns: WidgetColumns }>()

const { items, assistantId } = useExtensions('widgets', props.target)
const grid = computed(() => widgetGridClass(props.columns))
</script>

<template>
  <div v-if="items.length > 0" :class="grid" :data-solution-widgets="target">
    <SolutionMount
      v-for="widget in items"
      :key="widget.id"
      :class="cn('min-w-0', widgetSpanClass(widget.size, columns))"
      :data-widget-size="widget.size"
      :component="widget.component"
      :assistant-id="target === 'console' ? assistantId : null"
    />
  </div>
</template>
