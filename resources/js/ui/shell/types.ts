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

/** Strings every list screen built on the kit's DataTable shares (`table` in the console lang files). */
export interface TableTranslations {
    search: string
    search_hint: string
    empty: string
    empty_search: string
    clear_search: string
    selected: string
    clear_selection: string
    select_all: string
    select_row: string
    actions: string
    sort_by: string
    rows_per_page: string
    range: string
    page: string
    previous: string
    next: string
    expand_group: string
    collapse_group: string
}

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
    dashboard: Record<string, string | Record<string, string>>
    table: TableTranslations
    form: FormTranslations
}

export interface ShellPageProps {
    csrf_token: string
    locale: string
    translations: { console: ConsoleTranslations }
    auth: { user: ShellUser | null; permissions: string[] }
    accessState: { mode: string; notice: AccessNotice | null }
    supportAccess: { operatorName: string; operatorEmail: string; expiresAt: string } | null
    /** Whether broadcasting delivers anywhere (`null` and `log` do not); live screens poll when it does not. */
    broadcaster: { enabled: boolean; name: string }
    shell: ShellConfig
    navigation: ShellNavigation | null
    assistants: AssistantSwitcherData | null
    applications: ShellApplications | null
    [key: string]: unknown
}
