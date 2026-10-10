/**
 * A reply draft's identity is the id sent with it: the server sends one message per id, so the id stays while a draft is
 * retried and changes when the draft does.
 */
export function newRequestId(): string {
  const webCrypto = globalThis.crypto

  if (typeof webCrypto?.randomUUID === 'function') {
    return webCrypto.randomUUID()
  }

  const bytes = new Uint8Array(16)

  if (typeof webCrypto?.getRandomValues === 'function') {
    webCrypto.getRandomValues(bytes)
  } else {
    for (let i = 0; i < bytes.length; i++) {
      bytes[i] = Math.floor(Math.random() * 256)
    }
  }

  bytes[6] = (bytes[6] & 0x0f) | 0x40
  bytes[8] = (bytes[8] & 0x3f) | 0x80

  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

/** True when the file is larger than the limit in kilobytes. */
export function exceedsSize(sizeBytes: number, maxKb: number): boolean {
  return sizeBytes > maxKb * 1024
}

/** A size for a message: "512 KB" below a megabyte, "2.5 MB" above. */
export function formatSize(kb: number): string {
  return kb >= 1024 ? `${Math.round((kb / 1024) * 10) / 10} MB` : `${Math.round(kb)} KB`
}

/** Whether there is anything to send: text with a visible character, or a file. */
export function canSend(text: string, hasAttachment: boolean): boolean {
  return hasAttachment || text.trim() !== ''
}
