import type { ShellPageProps } from '@console/shell'

export type SettingValue = number | boolean | string

export interface SettingFieldTranslation {
  label: string
  description: string
}

export interface PlatformSettingsTranslations {
  title: string
  description: string
  saved: string
  default: string
  stored: string
  not_stored: string
  use_default: string
  default_option: string
  range: string
  min: string
  max: string
  on: string
  off: string
  groups: Record<string, string | undefined>
  fields: Record<string, SettingFieldTranslation | undefined>
}

export type PlatformSettingsPageProps = ShellPageProps & {
  translations: ShellPageProps['translations'] & {
    console: ShellPageProps['translations']['console'] & { platform_settings: PlatformSettingsTranslations }
  }
}

/** One setting as the server describes it; `value` is the stored value, null while the default applies. */
export interface PlatformSettingItem {
  key: string
  field: string
  type: 'integer' | 'boolean' | 'text' | 'choice'
  label: string
  description: string
  unit: string | null
  min: number | null
  max: number | null
  choices: string[]
  default: SettingValue
  stored: boolean
  value: SettingValue | null
}

export interface PlatformSettingsGroup {
  name: string
  settings: PlatformSettingItem[]
}
