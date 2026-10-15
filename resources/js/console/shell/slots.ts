import type { Component, InjectionKey } from 'vue'

/**
 * Where an application adds to the shell without the kit knowing it: a component `AppShell` renders in each slot,
 * given the slot as its `place` prop — `floating` (a fixed corner) and `header` (beside the header's controls). The
 * console provides the Solutions' shell elements here (`SolutionShellSlot`); without a provider the shell is unchanged.
 */
export type ShellSlotPlace = 'floating' | 'header'

export const SHELL_SLOT: InjectionKey<Component> = Symbol('fapost.shell-slot')
