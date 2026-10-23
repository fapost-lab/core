/**
 * One place that decides what a Branch (condition) rule is called for the author.
 *
 * The rule's `handle` is a generated UUID used as the edge identifier — it must never reach the UI. The canvas chip,
 * the outgoing-handle list and the structure tree all take their text from {@link branchRuleLabel}.
 */

export interface BranchRuleLike {
    left?:     unknown
    operator?: string
    value?:    unknown
    handle?:   string
    label?:    string
}

type Translate = (key: string) => string

/** Operators the Branch node offers, with the symbol used in auto-generated names. */
export const BRANCH_OPERATORS = [
    { value: 'eq',        symbol: '=' },
    { value: 'neq',       symbol: '≠' },
    { value: 'gt',        symbol: '>' },
    { value: 'gte',       symbol: '≥' },
    { value: 'lt',        symbol: '<' },
    { value: 'lte',       symbol: '≤' },
    { value: 'contains',  symbol: 'contains' },
    { value: 'in',        symbol: 'in' },
    { value: 'empty',     symbol: 'is empty' },
    { value: 'not_empty', symbol: 'is not empty' },
] as const

/** Operators that take no right-hand value. */
export const UNARY_OPERATORS: ReadonlySet<string> = new Set(['empty', 'not_empty'])

/** Dotted path of a compiled `left` operand (or a legacy string path); '' when no operand is chosen yet. */
export function leftPath(left: unknown): string {
    if (typeof left === 'string') {
        return left.trim()
    }
    if (!left || typeof left !== 'object') {
        return ''
    }
    const obj = left as Record<string, unknown>
    if (obj.ref === 'user_variable') {
        const v = (obj.variable && typeof obj.variable === 'object' ? obj.variable : {}) as Record<string, unknown>
        const name = typeof v.name === 'string' ? v.name : ''
        if (name === '') {
            return ''
        }
        if (v.storage === 'contact') {
            return typeof v.group === 'string' && v.group !== '' ? `contact.${v.group}.${name}` : `contact.${name}`
        }
        return `flow.${name}`
    }
    if (obj.ref === 'source' && typeof obj.source === 'string' && typeof obj.field === 'string'
        && obj.source !== '' && obj.field !== '') {
        return `${obj.source}.${obj.field}`
    }
    return ''
}

/** Compact "<left> <operator> <value>" text, or '' while the condition has no operand. */
export function branchAutoName(rule: BranchRuleLike): string {
    const path = leftPath(rule.left)
    if (path === '') {
        return ''
    }
    const operator = rule.operator ?? 'eq'
    const symbol = BRANCH_OPERATORS.find((op) => op.value === operator)?.symbol ?? operator
    if (UNARY_OPERATORS.has(operator)) {
        return `${path} ${symbol}`
    }
    const value = rule.value === undefined || rule.value === null ? '' : String(rule.value).trim()
    return value === '' ? `${path} ${symbol}` : `${path} ${symbol} ${value}`
}

/**
 * Display name of a rule: the author's own label, else the auto-name from the condition, else "Branch N".
 *
 * @param index zero-based position of the rule in the node
 */
export function branchRuleLabel(rule: BranchRuleLike, index: number, t?: Translate): string {
    const own = typeof rule.label === 'string' ? rule.label.trim() : ''
    if (own !== '') {
        return own
    }
    const auto = branchAutoName(rule)
    if (auto !== '') {
        return auto
    }
    const key = 'nodes.branch.branch_n'
    const translated = t ? t(key) : key
    return (translated === key ? 'Branch :n' : translated).replace(':n', String(index + 1))
}
