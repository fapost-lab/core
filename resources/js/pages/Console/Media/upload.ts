/**
 * The client side of a batch upload: one request per file, in order, so no request outgrows the server's
 * `post_max_size` however many files the person picked. Kept free of Vue and Inertia so it is tested on its own.
 */

/** How one file's request ended: stored, refused for the storage limit (the batch stops), or refused as invalid. */
export type UploadOutcome = 'saved' | 'refused' | 'invalid'

export interface BatchResult {
  saved: number
  total: number
  /** The index of the file the batch stopped at, `null` when every file was stored. */
  stoppedAt: number | null
}

/**
 * Sends the files one by one; `send` gets the file, its index, the size of the batch and how many were stored before
 * it (the server words the last answer for the whole batch from those). The first refusal ends the batch.
 */
export async function uploadSequentially<F>(
  files: readonly F[],
  send: (file: F, index: number, total: number, savedBefore: number) => Promise<UploadOutcome>,
): Promise<BatchResult> {
  let saved = 0

  for (const [index, file] of files.entries()) {
    const outcome = await send(file, index, files.length, saved)

    if (outcome !== 'saved') {
      return { saved, total: files.length, stoppedAt: index }
    }

    saved++
  }

  return { saved, total: files.length, stoppedAt: null }
}

/** The names of the files larger than `maxBytes`, refused before anything is sent. */
export function oversized(files: readonly { name: string; size: number }[], maxBytes: number): string[] {
  return files.filter((file) => file.size > maxBytes).map((file) => file.name)
}
