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
    MessagesSquare,
    Rocket,
    Settings,
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
    'messages-square': MessagesSquare,
    'rocket': Rocket,
    'settings': Settings,
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
