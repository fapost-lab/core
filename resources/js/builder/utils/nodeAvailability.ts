/**
 * Availability of a node on the canvas. A node whose Solution is turned off still comes with its metadata
 * (`available: false`); a node whose Solution is not installed has no metadata at all, but its type carries
 * the Solution id as the prefix before the first dot ("feedback.collect").
 */

import type {NodeTypePayload} from '@builder/dto/types'

export type NodeAvailability =
    | { state: 'available' }
    | { state: 'off', solutionName: string }
    | { state: 'missing', solutionId: string }

export function nodeAvailability(type: string, meta: NodeTypePayload | null | undefined): NodeAvailability {
    if (meta) {
        if (meta.available === false) {
            return {state: 'off', solutionName: meta.solution?.name ?? type.split('.')[0] ?? type}
        }

        return {state: 'available'}
    }

    const dot = type.indexOf('.')

    if (dot > 0) {
        return {state: 'missing', solutionId: type.slice(0, dot)}
    }

    return {state: 'available'}
}

export function isNodeUnavailable(availability: NodeAvailability): boolean {
    return availability.state !== 'available'
}

/** Reason line for the card; `t` is the builder translator, strings use `:name` / `:id` placeholders. */
export function unavailableReason(availability: NodeAvailability, t: (key: string) => string): string | null {
    if (availability.state === 'off') {
        return t('nodes.unavailable.solution_off').replace(':name', availability.solutionName)
    }

    if (availability.state === 'missing') {
        return t('nodes.unavailable.solution_missing').replace(':id', availability.solutionId)
    }

    return null
}

/** "feedback.collect" -> "Feedback Collect": label for a node without metadata. */
export function fallbackTypeLabel(type: string): string {
    return type.replace(/[_.]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}
