import { describe, expect, it } from 'vitest'
import { isSolutionName, parseSolutionName } from './names'

describe('Solution page names', () => {
  it('splits <id>::<Path>', () => {
    expect(parseSolutionName('showcase::Board/Index')).toEqual({ solution: 'showcase', path: 'Board/Index' })
    expect(parseSolutionName('crm_lite::Deals')).toEqual({ solution: 'crm_lite', path: 'Deals' })
  })

  it('tells a Solution name from a Core one by the separator', () => {
    expect(isSolutionName('showcase::Board/Index')).toBe(true)
    expect(isSolutionName('Console/Dashboard')).toBe(false)
  })

  it.each([
    'Console/Dashboard',
    '::Board',
    'showcase::',
    'Showcase::Board',
    's::Board',
    'showcase::Board::Index',
    'showcase::../Board',
    'showcase::/Board',
    'showcase::Board/',
    'show-case::Board',
  ])('rejects %s', (name) => {
    expect(parseSolutionName(name)).toBeNull()
  })
})
