import type { DataTableLabels } from '@fapost/ui/lib/messages'

/** What the server shares with every console page (see HandleInertiaRequests). */

export interface NavItem {
    key: string
    label: string
    href: string
    /** Lucide icon name in kebab case; see icons.ts. */
    icon: string
    /** The screen is not an Inertia page: a plain link with a full page load, never an Inertia visit. */
    external: boolean
    badge: string | null
}

export interface NavGroup {
    label: string | null
    items: NavItem[]
}

export interface ShellNavigation {
    mode: 'console' | 'admin'
    groups: NavGroup[]
    /** The search palette's endpoint (applications, their pages, then the records the user may list), in both modes. */
    searchUrl: string
}

/** One application of the rail, as `ApplicationRegistry` gives it. */
export interface ShellApplication {
    key: string
    label: string
    /** The caption under its icon in the rail. */
    short: string
    /** What it works on (per assistant, the whole organization), under its name in the menu. */
    scope: string
    /** Lucide icon name in kebab case; see icons.ts. */
    icon: string
    href: string
    /** Its home is not an Inertia page: a full page load, never an Inertia visit. */
    external: boolean
    /** It works per assistant: only then does the menu show the assistant switcher. */
    perAssistant: boolean
}

/** The application rail (see `ApplicationRail::props()`). */
export interface ShellApplications {
    /** The application the page belongs to. */
    current: string | null
    /** Every application the user may open, in the registry's order. */
    items: ShellApplication[]
    /** The pinned keys, in the user's order. */
    rail: string[]
    /** Where a new rail (`{applications: string[]}`) is sent. */
    railUrl: string
}

export interface ApplicationsTranslations {
    rail: string
    all: string
    find: string
    empty: string
    pinned: string
    pin: string
    unpin: string
    pin_named: string
    unpin_named: string
    move_up: string
    move_down: string
    rail_title: string
    rail_empty: string
    note: string
    save_failed: string
}

export interface SwitcherAssistant {
    id: string
    name: string
    href: string
    external: boolean
}

export interface AssistantSwitcherData {
    current: { id: string; name: string }
    items: SwitcherAssistant[]
}

export interface ShellConfig {
    localeUrl: string
    logoutUrl: string
    supportLeaveUrl: string
    locales: string[]
    /** The installation's name (`app.name`), the holder of the copyright in the footer. */
    appName: string
    /** The release this installation runs (`app.version`): an image tag such as `v0.18.2`, or `dev`. */
    version: string
}

/** The notification bell (see `HandleInertiaRequests::notifications()`); the list loads when it opens. */
export interface ShellNotifications {
    unread: number
    indexUrl: string
    countUrl: string
    readAllUrl: string
}

export interface NotificationsTranslations {
    label: string
    unread: string
    title: string
    mark_all: string
    unread_item: string
    open: string
    empty: string
    loading: string
    error: string
    untitled: string
}

export interface ShellUser {
    id: string
    name: string
    email: string
    isAdmin: boolean
}

export interface AccessNotice {
    title: string
    message: string | null
    actionLabel: string | null
    actionUrl: string | null
}

/** Strings every list screen built on the kit's DataTable shares (`table` in the console lang files); the shell hands them to the kit. */
export type TableTranslations = DataTableLabels

/** Strings every form screen shares (`form` in the console lang files). */
export interface FormTranslations {
    save: string
    saving: string
    cancel: string
    delete: string
    edit: string
    edit_named: string
    delete_named: string
}

export interface ConsoleTranslations {
    navigation: { dashboard: string; menu: string }
    breadcrumbs: { admin: string }
    switcher: { label: string }
    theme: { label: string; light: string; dark: string; system: string }
    language: { label: string } & Record<string, string>
    user_menu: { label: string; sign_out: string }
    notifications: NotificationsTranslations
    search: {
        open: string
        placeholder: string
        title: string
        description: string
        hint: string
        loading: string
        empty: string
        error: string
        groups: Record<'applications' | 'pages' | 'assistants' | 'users' | 'roles' | 'media', string>
    }
    applications: ApplicationsTranslations
    support: { banner: string; leave: string }
    workspace_deletion: { banner: string; cancel: string; cancelling: string }
    footer: { rights: string; powered_by: string }
    dashboard: Record<string, string | Record<string, string>>
    /** The close button of the kit's dialogs and sheets. */
    close: string
    table: TableTranslations
    form: FormTranslations
    onboarding: OnboardingTranslations
}

export interface ShellPageProps {
    csrf_token: string
    locale: string
    translations: { console: ConsoleTranslations }
    auth: { user: ShellUser | null; permissions: string[] }
    accessState: { mode: string; notice: AccessNotice | null }
    supportAccess: { operatorName: string; operatorEmail: string; expiresAt: string } | null
    /** While the workspace's deletion is scheduled, for its admins: when it starts (UTC, ISO 8601), who asked, where to cancel. */
    workspaceDeletionBanner?: { startsAt: string; requestedAt: string; requestedBy: string; cancelUrl: string } | null
    /** Whether broadcasting delivers anywhere (`null` and `log` do not); live screens poll when it does not. */
    broadcaster: { enabled: boolean; name: string }
    shell: ShellConfig
    navigation: ShellNavigation | null
    assistants: AssistantSwitcherData | null
    applications: ShellApplications | null
    /** `null` for a guest. */
    notifications: ShellNotifications | null
    /** Set for a platform-support session while the tenant is in its setup wizard; the wizard's pages send their steps instead. */
    onboarding?: { pending?: true; steps?: OnboardingStep[] } | null
    /** The setup checklist for this user; `null` when there is nothing to show (see `OnboardingChecklist::for()`). */
    onboardingChecklist?: OnboardingChecklistData | null
    /** The required steps that appeared after the tenant finished onboarding and are still open; `null` when none. */
    onboardingLateSteps?: OnboardingLateStepsData | null
    [key: string]: unknown
}

/** One line of the setup checklist, as `OnboardingChecklist::for()` gives it. */
export interface OnboardingChecklistItem {
  key: string
  required: boolean
  complete: boolean
  /** A path on this host; the step's page. */
  url: string
}

export interface OnboardingChecklistData {
  items: OnboardingChecklistItem[]
  remaining: number
  hideUrl: string
}

export interface OnboardingLateStepsData {
  steps: { key: string; url: string }[]
  /** The user may do the step (holds the settings permission). */
  canAct: boolean
}

/** One step of the setup wizard, as `OnboardingWizard::stepProps()` gives it. */
export interface OnboardingStep {
  key: string
  required: boolean
  complete: boolean
  current: boolean
}

interface StepText {
  title: string
  description: string
}

export interface OnboardingTranslations {
  done: string
  required_done: string
  skip: string
  continue: string
  back: string
  layout: { progress: string; step_of: string; completed: string; current: string; optional: string }
  steps: Record<string, StepText>
  languages: {
    title: string
    description: string
    notice_title: string
    notice: string
    base_language: string
    base_language_help: string
    base_language_locked: string
    available_languages: string
    available_help: string
    fallback_language: string
    fallback_help: string
    placeholder: string
    multi_placeholder: string
    selected: string
    search: string
    nothing_found: string
    remove: string
    continue: string
    continuing: string
  }
  assistant: {
    title: string
    description: string
    notice_title: string
    notice: string
    name: string
    name_help: string
    language: string
    language_help: string
    placeholder: string
    create: string
    creating: string
  }
  channel: { title: string; description: string; later: string }
  team: { title: string; description: string; later: string }
  waiting: { title: string; description: string; hint: string }
  support_banner: string
  checklist: {
    title: string
    progress: string
    remaining: string
    open: string
    optional: string
    done: string
    hide: string
  }
  late: {
    banner: string
    waiting: string
    action: string
    notification_title: string
    notification_body: string
  }
}

