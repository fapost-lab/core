/**
 * A new ULID written as an RFC 4122 string (lower-case `8-4-4-4-12` hex), the form the server's
 * `HasUlidPrimaryKey` gives a tenant-schema primary key (ADR-03): 48 bits of milliseconds since the epoch,
 * then 80 random bits. Used where the builder mints an id that becomes a row's primary key on publish —
 * a trigger's id is its `flow_triggers` row key (adr-20261119-flow-triggers-in-definition-live-on-publish).
 */
export function newUlidAsUuid(now: number = Date.now()): string {
    const bytes = new Uint8Array(16)
    let time = Math.max(0, Math.floor(now))

    for (let index = 5; index >= 0; index--) {
        bytes[index] = time % 256
        time = Math.floor(time / 256)
    }

    crypto.getRandomValues(bytes.subarray(6))

    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}
