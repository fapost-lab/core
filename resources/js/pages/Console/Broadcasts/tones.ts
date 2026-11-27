import type { BroadcastStatus, RecipientStatus } from './types'

type Tone = 'success' | 'info' | 'warning' | 'danger' | 'neutral'

/** The colour of a broadcast's status, the same on the list, the report and the dashboard. */
export const BROADCAST_TONES: Record<BroadcastStatus, Tone> = { draft: 'neutral', running: 'info', completed: 'success', cancelled: 'warning', failed: 'danger' }

/** The colour of a recipient's outcome on the report. */
export const RECIPIENT_TONES: Record<RecipientStatus, Tone> = { pending: 'info', sent: 'success', failed: 'danger', skipped: 'neutral' }
