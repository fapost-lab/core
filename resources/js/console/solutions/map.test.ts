import { describe, expect, it } from 'vitest'
import { findSolutionModule, parseSolutionBundleMap } from './map'

const json = JSON.stringify({
  showcase: {
    pages: { 'Board/Index': '/solutions/showcase/abc/assets/board.js' },
    components: { 'Widgets/Stats': '/solutions/showcase/abc/assets/stats.js' },
    css: ['/solutions/showcase/abc/assets/style.css'],
  },
})

describe('the map of active Solutions', () => {
  it('finds a page and its stylesheets', () => {
    expect(findSolutionModule(parseSolutionBundleMap(json), 'showcase::Board/Index', 'pages')).toEqual({
      url: '/solutions/showcase/abc/assets/board.js',
      css: ['/solutions/showcase/abc/assets/style.css'],
    })
  })

  it('keeps pages and components apart', () => {
    const map = parseSolutionBundleMap(json)

    expect(findSolutionModule(map, 'showcase::Widgets/Stats', 'components')?.url).toBe('/solutions/showcase/abc/assets/stats.js')
    expect(findSolutionModule(map, 'showcase::Widgets/Stats', 'pages')).toBeNull()
  })

  it('finds nothing for an inactive Solution, an unknown page or a malformed name', () => {
    const map = parseSolutionBundleMap(json)

    expect(findSolutionModule(map, 'other::Board/Index', 'pages')).toBeNull()
    expect(findSolutionModule(map, 'showcase::Board/Missing', 'pages')).toBeNull()
    expect(findSolutionModule(map, 'showcase::constructor', 'pages')).toBeNull()
    expect(findSolutionModule(map, 'Board/Index', 'pages')).toBeNull()
  })

  it('defaults missing lists to empty ones', () => {
    expect(parseSolutionBundleMap('{"showcase":{"pages":{"A":"/a.js"}}}')).toEqual({
      showcase: { pages: { A: '/a.js' }, components: {}, css: [] },
    })
  })

  it.each([
    [undefined],
    [''],
    ['not json'],
    ['[]'],
    ['null'],
  ])('reads %j as no Solutions', (input) => {
    expect(parseSolutionBundleMap(input)).toEqual({})
  })

  it('drops a malformed bundle or id and keeps the rest', () => {
    const map = parseSolutionBundleMap(
      '{"broken":{"pages":{"A":1}},"Not-An-Id":{"pages":{"A":"/a.js"}},"__proto__":{"pages":{"A":"/a.js"}},"showcase":{"pages":{"A":"/a.js"}}}',
    )

    expect(Object.keys(map)).toEqual(['showcase'])
  })
})
