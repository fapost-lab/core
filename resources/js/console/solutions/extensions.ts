import type { CellTone, CellType } from '@fapost/ui/components/data-table'

/**
 * The shared prop `extensions` (App\Http\Shell\SolutionExtensions::props()): what the Solutions served to the tenant
 * add to Core's screens, already filtered by the server for activation, ability and the open assistant. Every target
 * is present, empty when nothing is declared on it; items come in display order.
 */
export interface ExtensionBase {
  /** `<solution>.<key>`: unique across Solutions, the key of a tab in `?tab=` and of a column in a row. */
  id: string
  solution: string
  key: string
  order: number
}

export interface ShellExtension extends ExtensionBase {
  component: string
}

export interface LinkAction extends ExtensionBase {
  kind: 'link'
  label: string
  icon: string | null
  /** On the screen of the record the action is chosen on. */
  href?: string
  /** Elsewhere (a row of a list): the record's id goes in place of {@link RECORD_PLACEHOLDER}. */
  hrefTemplate?: string
}

export interface DialogAction extends ExtensionBase {
  kind: 'dialog'
  label: string
  icon: string | null
  component: string
}

export type ContextAction = LinkAction | DialogAction

export type WidgetSize = 'single' | 'double' | 'full'

export interface WidgetExtension extends ExtensionBase {
  component: string
  size: WidgetSize
}

export interface TabExtension extends ExtensionBase {
  label: string
  component: string
}

export interface ColumnExtension extends ExtensionBase {
  label: string
  cell: CellType
  tones: Partial<Record<string, CellTone>>
}

export type ShellSlotName = 'floating' | 'header'
export type ActionTarget = 'contact.view' | 'contact.row' | 'conversation.view'
export type WidgetTarget = 'console' | 'admin'
export type TabTarget = 'assistant' | 'tenant'
export type ColumnTarget = 'contacts' | 'conversations'

export interface ConsoleExtensions {
  shell: Record<ShellSlotName, ShellExtension[]>
  actions: Record<ActionTarget, ContextAction[]>
  widgets: Record<WidgetTarget, WidgetExtension[]>
  tabs: Record<TabTarget, TabExtension[]>
  columns: Record<ColumnTarget, ColumnExtension[]>
}

/** The id a `contact.row` action's route receives in place of the record (`SolutionExtensions::RECORD_PLACEHOLDER`). */
export const RECORD_PLACEHOLDER = '__record__'

type Group = keyof ConsoleExtensions

/**
 * The items on one target, or none when the prop is missing (a page outside the console, a guest) or malformed: a
 * broken prop must cost the Solutions their elements, not the screen.
 */
export function extensionsOn<G extends Group, T extends keyof ConsoleExtensions[G] & string>(
  extensions: unknown,
  group: G,
  target: T,
): ConsoleExtensions[G][T] {
  const targets = (extensions as Partial<Record<Group, Record<string, unknown>>> | null | undefined)?.[group]
  const items = targets?.[target]

  return (Array.isArray(items) ? items : []) as ConsoleExtensions[G][T]
}

/** Where a link action leads for a record; `null` when it has neither a ready link nor a template. */
export function actionHref(action: LinkAction, recordId: string): string | null {
  if (typeof action.href === 'string') {
    return action.href
  }

  if (typeof action.hrefTemplate === 'string') {
    return action.hrefTemplate.split(RECORD_PLACEHOLDER).join(encodeURIComponent(recordId))
  }

  return null
}

/** How many columns the widget grid of a dashboard has at its widest. */
export type WidgetColumns = 2 | 3

/** The classes of a widget grid. Literal strings, so Tailwind finds them. */
export function widgetGridClass(columns: WidgetColumns): string {
  return columns === 3 ? 'grid gap-4 sm:grid-cols-2 xl:grid-cols-3' : 'grid gap-4 md:grid-cols-2'
}

/** The span of a widget: `single` takes one column, `double` two, `full` the whole row. */
export function widgetSpanClass(size: WidgetSize, columns: WidgetColumns): string {
  if (columns === 3) {
    return size === 'full' ? 'sm:col-span-2 xl:col-span-3' : size === 'double' ? 'sm:col-span-2' : ''
  }

  return size === 'single' ? '' : 'md:col-span-2'
}

/**
 * The tab `?tab=` opens: one of Core's, one of the Solutions' by its id, otherwise the first.
 */
export function tabFromQuery<T extends string>(value: string | null, isCoreTab: (value: string | null) => value is T, solutionTabs: readonly { id: string }[], fallback: T): T | string {
  if (isCoreTab(value)) {
    return value
  }

  if (value !== null && solutionTabs.some((tab) => tab.id === value)) {
    return value
  }

  return fallback
}

/** A Solution column's value in a row (under the column's id), or `null` when the row has none a cell can show. */
export function rowValue(row: object, key: string): string | number | boolean | null {
  const value = (row as Record<string, unknown>)[key]

  return typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean' ? value : null
}
