import { describe, expect, it } from 'vitest'
import { oversized, uploadSequentially, type UploadOutcome } from './upload'

describe('uploadSequentially', () => {
  it('sends one file at a time, in order, with the count stored before it', async () => {
    const calls: string[] = []
    let inFlight = 0

    const result = await uploadSequentially(['a', 'b', 'c'], async (file, index, total, savedBefore) => {
      inFlight++
      expect(inFlight).toBe(1)
      calls.push(`${file}:${index}/${total}:${savedBefore}`)
      await Promise.resolve()
      inFlight--

      return 'saved'
    })

    expect(calls).toEqual(['a:0/3:0', 'b:1/3:1', 'c:2/3:2'])
    expect(result).toEqual({ saved: 3, total: 3, stoppedAt: null })
  })

  it.each<UploadOutcome>(['refused', 'invalid'])('stops at the first %s file', async (refusal) => {
    const sent: string[] = []

    const result = await uploadSequentially(['a', 'b', 'c'], async (file) => {
      sent.push(file)

      return file === 'b' ? refusal : 'saved'
    })

    expect(sent).toEqual(['a', 'b'])
    expect(result).toEqual({ saved: 1, total: 3, stoppedAt: 1 })
  })
})

describe('oversized', () => {
  it('names the files over the limit', () => {
    expect(oversized([{ name: 'small', size: 10 }, { name: 'big', size: 11 }], 10)).toEqual(['big'])
  })
})
