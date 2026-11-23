import {beforeEach, describe, expect, it} from 'vitest'
import {createPinia, setActivePinia} from 'pinia'
import {useSelectionStore} from './selectionStore'

describe('selectionStore triggers', () => {
    beforeEach(() => {
        setActivePinia(createPinia())
    })

    it('toggles a trigger on a second click', () => {
        const selection = useSelectionStore()

        selection.selectTrigger('t1')
        expect(selection.selectedTriggerId).toBe('t1')

        selection.selectTrigger('t1')
        expect(selection.selectedTriggerId).toBeNull()
    })

    it('selects a trigger by id without toggling it off', () => {
        const selection = useSelectionStore()

        selection.selectTriggerById('t1')
        selection.selectTriggerById('t1')

        expect(selection.selectedTriggerId).toBe('t1')
        expect(selection.selectedNodeId).not.toBe('t1')
    })
})
