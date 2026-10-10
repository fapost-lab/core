import type { ShellPageProps } from '@fapost/ui/shell'

export interface SupportAccessTranslations {
  title: string
  description: string
  columns: { operator: string; email: string; ip: string; entered_at: string; left_at: string }
  in_progress: string
  search_label: string
  empty: string
  empty_hint: string
}

export type SupportAccessPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { support_access: SupportAccessTranslations }
  }
}

export interface SupportAccessRow extends Record<string, unknown> {
  id: string
  operatorName: string
  operatorEmail: string
  ip: string | null
  enteredAt: string
  leftAt: string | null
  isOpen: boolean
}
