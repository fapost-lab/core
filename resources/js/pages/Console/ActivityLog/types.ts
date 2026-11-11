import type { ShellPageProps } from '@console/shell'

export interface ActivityLogTranslations {
  title: string
  description: string
  columns: { time: string; actor: string; action: string; subject: string; assistant: string }
  actor_kind: { support: string; system: string }
  search_label: string
  filters: {
    area: string
    area_all: string
    actor: string
    actor_all: string
    actor_support: string
    actor_system: string
    assistant: string
    assistant_all: string
    period: string
    period_7: string
    period_30: string
    period_90: string
    period_all: string
  }
  empty: string
  empty_hint: string
  reset: string
  details: string
  details_for: string
  sheet: {
    description: string
    changes: string
    old: string
    new: string
    empty_value: string
    markers: string
    markers_hint: string
    properties: string
    no_details: string
    support: string
    support_link: string
  }
}

export type ActivityLogPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { activity_log: ActivityLogTranslations }
  }
}

export interface ActivityChange {
  field: string
  old: string | null
  new: string | null
}

export interface ActivityProperty {
  key: string
  value: string
}

export interface ActivityRow extends Record<string, unknown> {
  id: string
  createdAt: string
  actorKind: 'staff' | 'support' | 'system'
  actorLabel: string | null
  action: string
  actionLabel: string
  area: string
  areaLabel: string
  subjectLabel: string | null
  assistantId: string | null
  assistantName: string | null
  values: ActivityChange[]
  markers: string[]
  properties: ActivityProperty[]
  supportEntryId: string | null
}

export interface ActivityLogFilterOptions {
  areas: { key: string; label: string }[]
  actors: { id: string; name: string }[]
  assistants: { id: string; name: string }[]
  periods: string[]
}
