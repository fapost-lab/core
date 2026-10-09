const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'

/**
 * A random secret of letters and digits, which is inside what Telegram accepts for a webhook secret token
 * (`A-Z a-z 0-9 _ -`, up to 256 characters). It is made in the browser from the system's random source, so it is
 * never requested from the server.
 *
 * Bytes above the largest multiple of the alphabet's size are dropped, so no character is likelier than another.
 */
export function generateSecret(length = 48, fill: (bytes: Uint8Array<ArrayBuffer>) => void = (bytes) => {
  crypto.getRandomValues(bytes)
}): string {
  const limit = 256 - (256 % ALPHABET.length)
  let secret = ''

  while (secret.length < length) {
    const bytes = new Uint8Array(length)
    fill(bytes)

    for (const byte of bytes) {
      if (byte < limit && secret.length < length) {
        secret += ALPHABET[byte % ALPHABET.length]
      }
    }
  }

  return secret
}
