import type { Arity, Condition, OperatorOption, SegmentSchema, TypeOption } from './types'

/**
 * The rule builder's logic on plain data. Which types, operators and value counts exist is the server's: it sends them
 * as the schema, and nothing here names a type or an operator.
 */

export function typeOf(schema: SegmentSchema, type: string): TypeOption | undefined {
  return schema.types.find((option) => option.value === type)
}

export function operatorOf(schema: SegmentSchema, type: string, operator: string): OperatorOption | undefined {
  return typeOf(schema, type)?.operators.find((option) => option.value === operator)
}

/** How many values the pair takes, or null when the pair is not one the schema knows. */
export function arityOf(schema: SegmentSchema, type: string, operator: string): Arity | null {
  return operatorOf(schema, type, operator)?.arity ?? null
}

/** Whether the condition can be shown: its type and operator are both in the schema, and fit. */
export function isKnown(schema: SegmentSchema, condition: Condition): boolean {
  return operatorOf(schema, condition.type, condition.operator) !== undefined
}

/** A fresh condition: the first type with its default operator (the first it offers) and nothing to compare to. */
export function newCondition(schema: SegmentSchema): Condition {
  const type = schema.types[0]

  return { type: type?.value ?? '', key: '', operator: type?.operators[0]?.value ?? '', value: [] }
}

/**
 * A type change starts the condition over within the new type: the default operator, no key and no value. The old
 * operator and value belong to the old type, and keeping them is how a `group` ends up with a `has`.
 */
export function changeType(condition: Condition, schema: SegmentSchema, type: string): Condition {
  return { ...condition, type, key: '', operator: typeOf(schema, type)?.operators[0]?.value ?? '', value: [] }
}

/** An operator change keeps the value as far as the new operator can read it: none for `exists`, the first for one. */
export function changeOperator(condition: Condition, schema: SegmentSchema, operator: string): Condition {
  const arity = arityOf(schema, condition.type, operator)
  const value = arity === 'none' ? [] : arity === 'one' ? condition.value.slice(0, 1) : condition.value

  return { ...condition, operator, value: [...value] }
}

/** The single value of a one-value condition, for a plain input. */
export function singleValue(condition: Condition): string {
  return condition.value[0] ?? ''
}

export function withSingleValue(condition: Condition, value: string): Condition {
  return { ...condition, value: value.trim() === '' ? [] : [value] }
}

/** The errors the server filed under one condition (`conditions.2.value`, `conditions.2.value.0`, …), by field. */
export function errorsOfCondition(errors: Record<string, string>, index: number): Record<string, string> {
  const prefix = `conditions.${index}.`
  const result: Record<string, string> = {}

  for (const [key, message] of Object.entries(errors)) {
    if (key.startsWith(prefix)) {
      const field = key.slice(prefix.length).split('.')[0] ?? ''

      result[field] ??= message
    }
  }

  return result
}
