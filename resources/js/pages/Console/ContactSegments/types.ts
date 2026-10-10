import type { ShellPageProps } from '@fapost/ui/shell'

export interface ContactSegmentsTranslations {
  title: string
  description: string
  size_note: string
  new: string
  create_title: string
  edit_title: string
  sections: { general: { title: string; description: string }; rules: { title: string; description: string } }
  columns: { name: string; conditions: string; size: string; counted_at: string }
  match_short: Record<string, string>
  fields: { name: string; match: string; conditions: string; type: string; key: string; operator: string; value: string }
  add_condition: string
  remove_condition: string
  condition_n: string
  no_conditions_hint: string
  ne_hint: string
  unknown_condition: string
  deleted_group: string
  pick_values: string
  no_options: string
  key_placeholder: string
  value_placeholder: string
  add_value: string
  remove_value: string
  value_hint: string
  search_label: string
  empty: string
  empty_hint: string
  recount: string
  recount_named: string
  current_size: string
  never_counted: string
  counted_at: string
  delete_one: { title: string; description: string }
}

export type ContactSegmentsPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { contact_segments: ContactSegmentsTranslations }
  }
}

/** How many values a condition takes. The server derives it from the domain; the client never guesses it. */
export type Arity = 'none' | 'one' | 'many'

export interface OperatorOption {
  value: string
  label: string
  arity: Arity
}

export interface TypeOption {
  value: string
  label: string
  needsKey: boolean
  operators: OperatorOption[]
}

/** What a condition can be made of: the combinators, and per type its operators. */
export interface SegmentSchema {
  match: { value: string; label: string }[]
  types: TypeOption[]
}

/** What a condition can pick from or be hinted with. Lists, so no order is lost on the way. */
export interface SegmentOptions {
  platforms: { value: string; label: string }[]
  groups: { id: string; name: string }[]
  languages: string[]
  tags: string[]
}

export interface Condition {
  type: string
  key: string
  operator: string
  value: string[]
}

export interface SegmentFields {
  name: string
  match: string
  conditions: Condition[]
}

export interface ContactSegmentRow extends Record<string, unknown> {
  id: string
  name: string
  match: string
  conditionsCount: number
  size: number | null
  countedAt: string | null
  editUrl: string
  deleteUrl: string
  countUrl: string
}
