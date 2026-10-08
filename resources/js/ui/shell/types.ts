/** What the server shares with every console page (see HandleInertiaRequests). */

export interface NavItem {
    key: string
    label: string
    href: string
    /** Lucide icon name in kebab case; see icons.ts. */
    icon: string
    /** The screen is still Filament's: a plain link with a full page load, never an Inertia visit. */
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
    back: { href: string; external: boolean }
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

export interface ConsoleTranslations {
    navigation: { dashboard: string; menu: string }
    breadcrumbs: { admin: string }
    switcher: { label: string; back_to_admin: string }
    theme: { label: string; light: string; dark: string; system: string }
    language: { label: string } & Record<string, string>
    user_menu: { label: string; sign_out: string }
    support: { banner: string; leave: string }
    dashboard: Record<string, string | Record<string, string>>
}

export interface ShellPageProps {
    csrf_token: string
    locale: string
    translations: { console: ConsoleTranslations }
    auth: { user: ShellUser | null; permissions: string[] }
    accessState: { mode: string; notice: AccessNotice | null }
    supportAccess: { operatorName: string; operatorEmail: string; expiresAt: string } | null
    shell: ShellConfig
    navigation: ShellNavigation | null
    assistants: AssistantSwitcherData | null
    [key: string]: unknown
}
