package logging

import (
	"crypto/sha256"
	"encoding/hex"
)

// fingerprintBytes is how much of the digest is kept. Eight bytes are ample to
// tell records apart in an investigation while leaving the value useless to
// anyone who obtains the logs.
const fingerprintBytes = 8

// Fingerprint renders a secret value as a stable, non-reversible tag.
//
// The webhook public hash travels in the URL and is the credential for a channel:
// whoever holds it can deliver to that channel. Logs get shipped to aggregators,
// copied into tickets and kept in backups, so the raw value must never enter them.
// A fingerprint still correlates every record about the same channel, which is all
// an investigation needs.
//
// An empty input returns an empty string rather than the digest of "", so absence
// stays visibly distinct from a real value.
func Fingerprint(secret string) string {
	if secret == "" {
		return ""
	}

	digest := sha256.Sum256([]byte(secret))

	return hex.EncodeToString(digest[:fingerprintBytes])
}
