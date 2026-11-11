import { interpolate } from '@console/shell'

/** Where a node copied from a flow template came from; null for a node of the flow itself. */
export interface TemplateNodeOrigin {
  template: string
  version: number
  nodeId: string
  nodeType: string
}

/** "Template «X» v3 → send_message abc123", from the screen's `template_node` string. */
export function templateNodeLabel(pattern: string, origin: TemplateNodeOrigin): string {
  return interpolate(pattern, {
    template: origin.template,
    version: origin.version,
    type: origin.nodeType,
    node: origin.nodeId,
  })
}
