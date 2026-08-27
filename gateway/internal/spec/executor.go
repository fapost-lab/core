package spec

import (
	"crypto/hmac"
	"crypto/sha1"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"hash"
	"regexp"
	"strconv"
	"strings"
)

const (
	channelPlaceholder = "channel"
	bodyPrefix         = "body."
	headerPrefix       = "header."
)

var placeholderPattern = regexp.MustCompile(`\{([^}]+)\}`)

// Verify reports whether the request carries a valid signature for the secret.
//
// Every comparison is constant-time: a timing oracle here would leak the channel
// secret to anyone who knows the public hash, which travels in the URL.
func (s Spec) Verify(r *Request, secret string) bool {
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

func (s Spec) verifyHMAC(r *Request, secret string, newHash func() hash.Hash) bool {
	mac := hmac.New(newHash, []byte(secret))
	mac.Write(r.RawBody)

	expected := s.Prefix + hex.EncodeToString(mac.Sum(nil))

	return hmac.Equal([]byte(expected), []byte(r.Header(s.parameter())))
}

// IdempotencyKey resolves the spec's template against the request.
//
// Unresolved placeholders collapse to an empty string, matching the PHP executor.
func (s Spec) IdempotencyKey(r *Request, channelID string) string {
	return placeholderPattern.ReplaceAllStringFunc(s.Idempotency, func(match string) string {
		return resolvePlaceholder(strings.TrimSuffix(strings.TrimPrefix(match, "{"), "}"), r, channelID)
	})
}

func resolvePlaceholder(placeholder string, r *Request, channelID string) string {
	switch {
	case placeholder == channelPlaceholder:
		return channelID

	case strings.HasPrefix(placeholder, headerPrefix):
		return r.Header(strings.TrimPrefix(placeholder, headerPrefix))

	case strings.HasPrefix(placeholder, bodyPrefix):
		return scalarAtPath(r.decodedBody(), strings.TrimPrefix(placeholder, bodyPrefix))

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
		if err != nil || index < 0 || index >= len(node) {
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
