import type { ShellPageProps } from '@console/shell'
import type { ActivityPeriod } from '../FlowSessions/types'

/** How often something ran in the period; every figure is null for a user who may not view the flow log. */
export interface RunFigures {
  executions: number | null
  failures: number | null
  lastRunAt: string | null
}

/** One `type@version`, as `NodeUsageController::nodeRow()` builds it. */
export interface NodeUsageRow extends RunFigures {
  key: string
  type: string
  version: number
  /** The type's label as its latest registered version names it; null for a type nobody registers now. */
  label: string | null
  /** The Solution that registered the type; null for Core's and for an unregistered one. */
  solution: string | null
  registered: boolean
  /** In an active version with no handler for its version: the flow fails when it reaches the node. */
  orphaned: boolean
  activeFlows: number
  activeNodes: number
  versions: number
}

/** One action the `call` nodes send to, as `NodeUsageController::actionRow()` builds it. */
export interface ActionUsageRow extends RunFigures {
  id: string
  solution: string | null
  registered: boolean
  activeFlows: number
  versions: number
}

export interface NodeUsageTranslations {
  navigation: string
  title: string
  description: string
  period: string
  periods: Record<ActivityPeriod, string>
  window: string
  runs_hidden: string
  scoped: string
  nodes: { title: string; description: string; empty: string }
  actions: { title: string; description: string; empty: string }
  unused: { title: string; description: string }
  columns: Record<'node' | 'action' | 'active_flows' | 'active_nodes' | 'versions' | 'executions' | 'failures' | 'last_run', string>
  hints: Record<'active_flows' | 'active_nodes' | 'versions', string>
  core: string
  solution: string
  missing: string
  orphaned: string
  never: string
}

export interface NodeUsagePageData {
  nodes: NodeUsageRow[]
  actions: ActionUsageRow[]
  /** Registered types nothing uses; null for a user who sees only some assistants. */
  unused: { type: string; label: string }[] | null
  /** Whether the run figures are given (the user may view the flow log). */
  runs: boolean
  period: ActivityPeriod
  /** The period applied without `?period` (NodeUsageController::DEFAULT_PERIOD). */
  default: ActivityPeriod
  /** The query parameter that carries the period (NodeUsageController::PERIOD). */
  param: string
  periods: ActivityPeriod[]
}

export type NodeUsagePageProps = ShellPageProps & {
  translations: { console: ShellPageProps['translations']['console'] & { node_usage: NodeUsageTranslations } }
}
