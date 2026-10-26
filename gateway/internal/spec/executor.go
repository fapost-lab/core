package spec

import (
	"crypto/hmac"
	"crypto/sha1"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"hash"
	"math/big"
	"regexp"
	"strconv"
	"strings"
	"time"
)

const (
	channelPlaceholder = "channel"
	rawPlaceholderName = "raw"
	bodyPrefix         = "body."
	headerPrefix       = "header."
)

var placeholderPattern = regexp.MustCompile(`\{([^}]+)\}`)

// ErrTimestamp reports that the request-age check refused the request: the header was missing,
// malformed, or further from now than the tolerance. It is for the operator's log, never the caller.
var ErrTimestamp = errors.New("ingress spec: stale or malformed timestamp")

// ErrSignature reports that the secret, the signature or the credential comparison failed.
var ErrSignature = errors.New("ingress spec: signature mismatch")

var timestampPattern = regexp.MustCompile(`^[0-9]{1,19}$`)

// Verify reports whether the request carries a valid signature for the secret, judged at the current time.
func (s Spec) Verify(r *Request, secret string) bool {
	return s.VerifyAt(r, secret, time.Now().Unix())
}

// VerifyAt reports whether the request carries a valid signature for the secret, judged at now
// (unix seconds, used by the timestamp check).
//
// Every comparison is constant-time: a timing oracle here would leak the channel
// secret to anyone who knows the public hash, which travels in the URL.
//
// An empty secret refuses every scheme that reads one: compared directly it
// matches an absent header, and as an HMAC key it lets anyone sign.
func (s Spec) VerifyAt(r *Request, secret string, now int64) bool {
	return s.Check(r, secret, now) == nil
}

// Check is VerifyAt that says why a request was refused: nil when verified, ErrTimestamp when the
// request-age check failed, ErrSignature otherwise.
func (s Spec) Check(r *Request, secret string, now int64) error {
	if s.Scheme != SchemeNone && secret == "" {
		return ErrSignature
	}

	if s.Timestamp != nil && !s.timestampIsFresh(r, now) {
		return ErrTimestamp
	}

	if !s.verifySignature(r, secret) {
		return ErrSignature
	}

	return nil
}

func (s Spec) verifySignature(r *Request, secret string) bool {
	switch s.Scheme {
	case SchemeNone:
		return true

	case SchemeHeaderEquals:
		return hmac.Equal([]byte(secret), []byte(r.Header(s.parameter())))

	case SchemeQueryParam:
		return hmac.Equal([]byte(secret), []byte(r.QueryParam(s.parameter())))

	case SchemeHMACSHA256:
		return s.verifyHMAC(r, secret, sha256.New)

	case SchemeHMACSHA1:
		return s.verifyHMAC(r, secret, sha1.New)

	default:
		// Unreachable for a validated spec. Denying is the only safe default:
		// an unrecognized scheme must never behave like SchemeNone.
		return false
	}
}

// timestampIsFresh applies the request-age check: 1 to 19 ASCII digits that fit an int64, within
// the tolerance of now in either direction. Anything else is refused, never skipped.
func (s Spec) timestampIsFresh(r *Request, now int64) bool {
	value := r.Header(s.Timestamp.Header)

	if !timestampPattern.MatchString(value) {
		return false
	}

	stamp, err := strconv.ParseInt(value, 10, 64)
	if err != nil {
		return false
	}

	distance := new(big.Int).Sub(big.NewInt(now), big.NewInt(stamp))

	return distance.CmpAbs(big.NewInt(int64(s.Timestamp.Tolerance))) <= 0
}

func (s Spec) verifyHMAC(r *Request, secret string, newHash func() hash.Hash) bool {
	message := string(r.RawBody)

	if s.Signed != nil {
		message = s.resolve(*s.Signed, r, "", nil, true)
	}

	mac := hmac.New(newHash, []byte(secret))
	mac.Write([]byte(message))

	expected := s.Prefix + hex.EncodeToString(mac.Sum(nil))

	return hmac.Equal([]byte(expected), []byte(r.Header(s.parameter())))
}

// IdempotencyKey resolves the spec's template against the request document.
//
// Unresolved placeholders collapse to an empty string, matching the PHP executor.
func (s Spec) IdempotencyKey(r *Request, channelID string) string {
	return s.resolve(s.Idempotency, r, channelID, s.Document(r), false)
}

// Document returns the request document: the JSON body when it is an object or array, else the
// JSON held in the spec's form field, else nil. Content-Type is never consulted.
func (s Spec) Document(r *Request) any {
	return r.document(s.Form)
}

// HandshakeResponse returns the plain-text answer to a provider's challenge, and whether the
// request is one. Call it only after verification succeeded.
func (s Spec) HandshakeResponse(r *Request) (string, bool) {
	if s.Handshake == nil {
		return "", false
	}

	document := s.Document(r)

	if s.resolve(s.Handshake.When, r, "", document, false) != s.Handshake.Equals {
		return "", false
	}

	return s.resolve(s.Handshake.Respond, r, "", document, false), true
}

// resolve expands every {placeholder} of a template. A placeholder may list alternatives
// separated by '|'; the first one resolving to a non-empty string wins.
func (s Spec) resolve(template string, r *Request, channelID string, document any, allowRaw bool) string {
	return placeholderPattern.ReplaceAllStringFunc(template, func(match string) string {
		inner := strings.TrimSuffix(strings.TrimPrefix(match, "{"), "}")

		for _, alternative := range strings.Split(inner, "|") {
			if value := resolvePlaceholder(alternative, r, channelID, document, allowRaw); value != "" {
				return value
			}
		}

		return ""
	})
}

func resolvePlaceholder(placeholder string, r *Request, channelID string, document any, allowRaw bool) string {
	switch {
	case placeholder == channelPlaceholder:
		return channelID

	case placeholder == rawPlaceholderName:
		if allowRaw {
			return string(r.RawBody)
		}

		return ""

	case strings.HasPrefix(placeholder, headerPrefix):
		return r.Header(strings.TrimPrefix(placeholder, headerPrefix))

	case strings.HasPrefix(placeholder, bodyPrefix):
		return scalarAtPath(document, strings.TrimPrefix(placeholder, bodyPrefix))

	default:
		return ""
	}
}

// scalarAtPath walks a dot-separated path through the decoded body.
func scalarAtPath(cursor any, path string) string {
	for _, segment := range strings.Split(path, ".") {
		next, ok := child(cursor, segment)
		if !ok {
			return ""
		}

		cursor = next
	}

	return stringify(cursor)
}

// child descends one path segment.
//
// Arrays are addressable by numeric segment because PHP array_key_exists treats
// a numeric string as an integer key; without this the two runtimes would
// disagree on a body whose root or branch is a JSON array.
func child(cursor any, segment string) (any, bool) {
	switch node := cursor.(type) {
	case map[string]any:
		value, ok := node[segment]

		return value, ok

	case []any:
		index, err := strconv.Atoi(segment)
		// Canonical form only: PHP does not treat "01" or "+1" as the integer key 1.
		if err != nil || index < 0 || index >= len(node) || strconv.Itoa(index) != segment {
			return nil, false
		}

		return node[index], true

	default:
		return nil, false
	}
}

// stringify renders a scalar the way the PHP executor does.
//
// Numbers render as values rather than as their JSON spelling: PHP casts the
// decoded number, so 1.0 becomes "1". Echoing the literal would emit "1.0" and
// produce a different deduplication key for the same message.
func stringify(value any) string {
	switch typed := value.(type) {
	case string:
		return typed

	case json.Number:
		if integer, err := strconv.ParseInt(typed.String(), 10, 64); err == nil {
			return strconv.FormatInt(integer, 10)
		}

		if float, err := strconv.ParseFloat(typed.String(), 64); err == nil {
			return strconv.FormatFloat(float, 'f', -1, 64)
		}

		return ""

	case bool:
		if typed {
			return "1"
		}

		return "0"

	default:
		return ""
	}
}
