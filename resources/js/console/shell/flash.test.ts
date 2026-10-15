import {describe, expect, it} from 'vitest'
import {toastsFor} from './flash'

describe('toastsFor', () => {
    it('is empty without messages', () => {
        expect(toastsFor(null)).toEqual([])
        expect(toastsFor(undefined)).toEqual([])
        expect(toastsFor({success: null, error: null})).toEqual([])
        expect(toastsFor({success: '', error: ''})).toEqual([])
    })

    it('makes a toast of each message, the error first', () => {
        expect(toastsFor({success: 'Saved.', error: 'Failed.'})).toEqual([
            {kind: 'error', message: 'Failed.'},
            {kind: 'success', message: 'Saved.'},
        ])
    })

    it('makes a toast of a lone success', () => {
        expect(toastsFor({success: 'Saved.'})).toEqual([{kind: 'success', message: 'Saved.'}])
    })
})
