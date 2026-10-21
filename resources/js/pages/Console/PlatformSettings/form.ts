import type { PlatformSettingItem, PlatformSettingsGroup, PlatformSettingsTranslations, SettingValue } from './types'

/** What a select holds while the setting uses its default (a select cannot hold null). */
export const DEFAULT_OPTION = '__default__'

/** The form's initial values by field name: the stored value, or null while the default applies. */
export function initialValues(groups: PlatformSettingsGroup[]): Record<string, SettingValue | null> {
  const values: Record<string, SettingValue | null> = {}

  for (const group of groups) {
    for (const setting of group.settings) {
      values[setting.field] = setting.value
    }
  }

  return values
}

/** The label and description of a setting: translated when the locale has them, the definition's English otherwise. */
export function textsOf(setting: PlatformSettingItem, t: PlatformSettingsTranslations): { label: string; description: string } {
  const translated = t.fields[setting.field]

  return { label: translated?.label ?? setting.label, description: translated?.description ?? setting.description }
}

/** The group's heading: translated by its lower-case name when the locale has it. */
export function groupTitle(name: string, t: PlatformSettingsTranslations): string {
  return t.groups[name.toLowerCase()] ?? name
}

/** A select value back to the form's: the default option is null, a boolean option is a boolean. */
export function fromSelect(setting: PlatformSettingItem, option: string): SettingValue | null {
  if (option === DEFAULT_OPTION) {
    return null
  }

  return setting.type === 'boolean' ? option === 'true' : option
}

/** The form's value as a select option. */
export function toSelect(value: SettingValue | null | undefined): string {
  return value === null || value === undefined ? DEFAULT_OPTION : String(value)
}
