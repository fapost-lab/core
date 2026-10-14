import type {Component} from 'vue'
import {
    ArrowLeftRight,
    Circle,
    CircleUser,
    FileText,
    FolderOpen,
    Funnel,
    House,
    Image,
    Languages,
    LifeBuoy,
    LayoutGrid,
    List,
    Megaphone,
    MessageSquare,
    MessagesSquare,
    Rocket,
    Settings,
    Shield,
    ShieldCheck,
    Signal,
    SlidersHorizontal,
    Users,
    UsersRound,
} from '@lucide/vue'

/**
 * Icon names the server sends (lucide, kebab case) and the components they stand for. The server keeps to this
 * list; an unknown name falls back to a plain circle so a typo never breaks the menu.
 */
const ICONS: Record<string, Component> = {
    'arrow-left-right': ArrowLeftRight,
    'circle-user': CircleUser,
    'file-text': FileText,
    'folder-open': FolderOpen,
    'funnel': Funnel,
    'home': House,
    'image': Image,
    'languages': Languages,
    'life-buoy': LifeBuoy,
    'layout-grid': LayoutGrid,
    'list': List,
    'megaphone': Megaphone,
    'message-square': MessageSquare,
    'messages-square': MessagesSquare,
    'rocket': Rocket,
    'settings': Settings,
    'shield': Shield,
    'shield-check': ShieldCheck,
    'signal': Signal,
    'sliders-horizontal': SlidersHorizontal,
    'users': Users,
    'users-round': UsersRound,
}

export function iconFor(name: string): Component {
    return ICONS[name] ?? Circle
}

export const KNOWN_ICON_NAMES: readonly string[] = Object.keys(ICONS)

/** A lucide name in kebab case, as the server sends it (`lamp-desk`, `arrow-down-0-1`). */
const ICON_NAME = /^[a-z0-9]+(?:-[a-z0-9]+)*$/

/** The export name lucide gives an icon: `lamp-desk` → `LampDesk`, `arrow-down-0-1` → `ArrowDown01`. */
export function lucideExportName(name: string): string {
    return name
        .split('-')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join('')
}

let catalogue: Promise<Record<string, Component>> | null = null

/**
 * Any lucide icon by its kebab-case name: Core's own at once, any other (a Solution's application, menu or action) from
 * the lazy catalogue chunk, fetched once on the first such name. Resolves `null` for a name lucide does not have or a
 * catalogue that failed to load, so the caller keeps its fallback.
 */
export async function loadIcon(name: string): Promise<Component | null> {
    if (Object.hasOwn(ICONS, name)) {
        return ICONS[name]
    }

    if (!ICON_NAME.test(name)) {
        return null
    }

    catalogue ??= import('./lucide-catalogue').then(
        (module) => module.icons as unknown as Record<string, Component>,
        (error: unknown) => {
            catalogue = null
            throw error
        },
    )

    try {
        const icons = await catalogue

        return Object.hasOwn(icons, lucideExportName(name)) ? icons[lucideExportName(name)] : null
    } catch {
        return null
    }
}
