import { describe, expect, it } from 'vitest'
import { arityOf, changeOperator, changeType, errorsOfCondition, isKnown, newCondition, singleValue, withSingleValue } from './rules'
import type { Condition, SegmentSchema } from './types'

const schema: SegmentSchema = {
  match: [
    { value: 'all', label: 'All' },
    { value: 'any', label: 'Any' },
  ],
  types: [
    {
      value: 'tag',
      label: 'Tag',
      needsKey: false,
      operators: [
        { value: 'has', label: 'has', arity: 'one' },
        { value: 'not_has', label: 'has not', arity: 'one' },
      ],
    },
    {
      value: 'attribute',
      label: 'Attribute',
      needsKey: true,
      operators: [
        { value: 'eq', label: 'equals', arity: 'one' },
        { value: 'exists', label: 'is set', arity: 'none' },
      ],
    },
    {
      value: 'group',
      label: 'Group',
      needsKey: false,
      operators: [
        { value: 'in', label: 'any of', arity: 'many' },
        { value: 'not_in', label: 'none of', arity: 'many' },
      ],
    },
  ],
}

describe('newCondition', () => {
  it('starts with the first type and its default operator', () => {
    expect(newCondition(schema)).toEqual({ type: 'tag', key: '', operator: 'has', value: [] })
  })

  it('does not fail on an empty schema', () => {
    expect(newCondition({ match: [], types: [] })).toEqual({ type: '', key: '', operator: '', value: [] })
  })
})

describe('arityOf', () => {
  it('reads the arity of a pair from the schema', () => {
    expect(arityOf(schema, 'tag', 'has')).toBe('one')
    expect(arityOf(schema, 'attribute', 'exists')).toBe('none')
    expect(arityOf(schema, 'group', 'not_in')).toBe('many')
  })

  it('knows nothing of a pair that does not fit', () => {
    expect(arityOf(schema, 'group', 'has')).toBeNull()
    expect(arityOf(schema, 'weather', 'is')).toBeNull()
  })
})

describe('isKnown', () => {
  it('is false for a type or an operator the schema lacks', () => {
    expect(isKnown(schema, { type: 'tag', key: '', operator: 'has', value: [] })).toBe(true)
    expect(isKnown(schema, { type: 'tag', key: '', operator: 'in', value: [] })).toBe(false)
    expect(isKnown(schema, { type: 'weather', key: '', operator: 'is', value: [] })).toBe(false)
  })
})

describe('changeType', () => {
  it('resets the operator to the first of the new type and clears the key and the value', () => {
    const condition: Condition = { type: 'attribute', key: 'age', operator: 'exists', value: ['x'] }

    expect(changeType(condition, schema, 'group')).toEqual({ type: 'group', key: '', operator: 'in', value: [] })
  })

  it('never leaves an operator of the old type behind', () => {
    const changed = changeType({ type: 'tag', key: '', operator: 'not_has', value: ['vip'] }, schema, 'group')

    expect(isKnown(schema, changed)).toBe(true)
  })
})

describe('changeOperator', () => {
  it('drops the value for an operator that takes none', () => {
    const condition: Condition = { type: 'attribute', key: 'age', operator: 'eq', value: ['18'] }

    expect(changeOperator(condition, schema, 'exists')).toEqual({ type: 'attribute', key: 'age', operator: 'exists', value: [] })
  })

  it('cuts a list to the first value for an operator that takes one', () => {
    const condition: Condition = { type: 'group', key: '', operator: 'in', value: ['a', 'b'] }

    expect(changeOperator({ ...condition, type: 'tag', operator: 'has' }, schema, 'not_has').value).toEqual(['a'])
  })

  it('keeps the list for an operator that takes many', () => {
    const condition: Condition = { type: 'group', key: '', operator: 'in', value: ['a', 'b'] }

    expect(changeOperator(condition, schema, 'not_in').value).toEqual(['a', 'b'])
  })

  it('does not change the condition it was given', () => {
    const condition: Condition = { type: 'group', key: '', operator: 'in', value: ['a', 'b'] }

    changeOperator(condition, schema, 'not_in')

    expect(condition.value).toEqual(['a', 'b'])
  })
})

describe('single values', () => {
  it('reads the first value and writes a list of one', () => {
    const condition: Condition = { type: 'tag', key: '', operator: 'has', value: [] }

    expect(singleValue(condition)).toBe('')
    expect(withSingleValue(condition, 'vip').value).toEqual(['vip'])
    expect(singleValue(withSingleValue(condition, 'vip'))).toBe('vip')
  })

  it('writes nothing for blank text', () => {
    expect(withSingleValue({ type: 'tag', key: '', operator: 'has', value: ['a'] }, '  ').value).toEqual([])
  })
})

describe('errorsOfCondition', () => {
  const errors = {
    name: 'bad name',
    'conditions.1.operator': 'wrong operator',
    'conditions.1.value': 'one value',
    'conditions.1.value.0': 'too long',
    'conditions.10.key': 'not mine',
    'conditions.2.value': 'other row',
  }

  it('collects the errors of one row by field', () => {
    expect(errorsOfCondition(errors, 1)).toEqual({ operator: 'wrong operator', value: 'one value' })
  })

  it('does not take row 10 for row 1', () => {
    expect(errorsOfCondition(errors, 10)).toEqual({ key: 'not mine' })
    expect(errorsOfCondition(errors, 0)).toEqual({})
  })
})
