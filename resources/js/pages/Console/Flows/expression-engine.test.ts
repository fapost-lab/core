import { describe, expect, it } from 'vitest'
import { engineToSend, offersEngineChoice } from './expressionEngine'

describe('offersEngineChoice', () => {
  it('hides the choice while the one installed engine is the flow\'s own', () => {
    expect(offersEngineChoice(['template'], 'template')).toBe(false)
  })

  it('offers it while several engines are installed', () => {
    expect(offersEngineChoice(['template', 'second'], 'template')).toBe(true)
  })

  it('offers it while the flow\'s engine is no longer installed, even with one left', () => {
    expect(offersEngineChoice(['template'], 'gone')).toBe(true)
  })
})

describe('engineToSend', () => {
  it('sends nothing while there is no choice, so the flow keeps its engine', () => {
    expect(engineToSend(['template'], 'template', 'template')).toBeUndefined()
  })

  it('sends the chosen installed engine', () => {
    expect(engineToSend(['template', 'second'], 'template', 'second')).toBe('second')
    expect(engineToSend(['template'], 'gone', 'template')).toBe('template')
  })

  it('sends nothing while the select still holds an engine that is gone', () => {
    expect(engineToSend(['template'], 'gone', 'gone')).toBeUndefined()
  })
})
