// Package spec executes ingress specs published by the PHP application.
//
// A spec describes how a channel's inbound webhook is authenticated and
// deduplicated as data rather than code, which is what lets this gateway verify
// platforms it has never heard of — including ones shipped by plugins. Adding a
// channel stays a pure PHP change and needs no gateway release.
//
// The behaviour here is not free to evolve independently: it is pinned by
// contracts/ingress/golden.json, which the PHP test suite executes against its
// own implementation. Any change must keep both sides passing that file.
package spec

import (
	"encoding/json"
	"fmt"
	"regexp"
	"strings"
)

const (
	// VersionV1 is the original wire format: signature over the raw body, JSON body only.
	VersionV1 = 1

	// Version is the newest wire format this package understands. It matches
	// IngressSpec::VERSION_2 on the PHP side and must be bumped in lockstep.
	// Version 2 adds the signed template, the timestamp check, the form field,
	// the handshake and idempotency alternatives.
	Version = 2

	rawPlaceholder = "{raw}"

	// maxTimestampTolerance mirrors IngressTimestamp::MAX_TOLERANCE_SECONDS.
	maxTimestampTolerance = 3600
)

var formFieldPattern = regexp.MustCompile(`^[A-Za-z0-9_.\-]{1,64}$`)

// Scheme identifies how a request signature is checked.
type Scheme string

const (
	// SchemeNone performs no signature check: the unguessable public hash is
	// the only credential.
	SchemeNone Scheme = "none"

	// SchemeHeaderEquals compares a header against the channel secret (Telegram).
	SchemeHeaderEquals Scheme = "header_equals"

	// SchemeHMACSHA256 compares a header against an HMAC-SHA256 of the raw body.
	SchemeHMACSHA256 Scheme = "hmac_sha256"

	// SchemeHMACSHA1 compares a header against an HMAC-SHA1 of the raw body.
	SchemeHMACSHA1 Scheme = "hmac_sha1"

	// SchemeQueryParam compares a query parameter against the channel secret.
	SchemeQueryParam Scheme = "query_param"
)

// requiresParameter reports whether the scheme reads a named parameter.
func (s Scheme) requiresParameter() bool {
	return s != SchemeNone
}

// valid reports whether the scheme is one this gateway can execute.
//
// An unknown scheme is a spec published by a newer application than this binary.
// It must never be treated as SchemeNone, which would wave the request through
// unverified; callers reject the spec instead and fall back to the PHP ingress.
func (s Scheme) valid() bool {
	switch s {
	case SchemeNone, SchemeHeaderEquals, SchemeHMACSHA256, SchemeHMACSHA1, SchemeQueryParam:
		return true
	default:
		return false
	}
}

// Spec is the declarative ingress contract for one platform.
type Spec struct {
	Version     int     `json:"v"`
	Scheme      Scheme  `json:"scheme"`
	Parameter   *string `json:"parameter"`
	Prefix      string  `json:"prefix"`
	Idempotency string  `json:"idempotency"`

	// Version 2 fields. All are absent (nil) in a version 1 spec.

	// Signed is the template of the string the HMAC covers; nil signs the raw body.
	Signed *string `json:"signed"`

	// Timestamp is the request-age check.
	Timestamp *Timestamp `json:"timestamp"`

	// Form names the form field holding the JSON document when the body is not JSON.
	Form *string `json:"form"`

	// Handshake is the endpoint-ownership challenge answered without enqueueing.
	Handshake *Handshake `json:"handshake"`
}

// Timestamp is the request-age check: the header carries the unix seconds the
// provider signed the request at.
type Timestamp struct {
	Header    string `json:"header"`
	Tolerance int    `json:"tolerance"`
}

// Handshake is a provider's challenge: when When resolves to exactly Equals the
// answer is the resolved Respond template.
type Handshake struct {
	When    string `json:"when"`
	Equals  string `json:"equals"`
	Respond string `json:"respond"`
}

// Parse decodes a spec from its published JSON form, rejecting anything this
// build cannot execute faithfully.
func Parse(data []byte) (Spec, error) {
	var s Spec

	if err := json.Unmarshal(data, &s); err != nil {
		return Spec{}, fmt.Errorf("ingress spec: malformed JSON: %w", err)
	}

	return s, s.Validate()
}

// Validate reports whether the spec is executable by this build.
func (s Spec) Validate() error {
	if s.Version != VersionV1 && s.Version != Version {
		return fmt.Errorf("ingress spec: unsupported version %d (this build speaks %d and %d)", s.Version, VersionV1, Version)
	}

	if s.Version == VersionV1 && (s.Signed != nil || s.Timestamp != nil || s.Form != nil || s.Handshake != nil) {
		return fmt.Errorf("ingress spec: version 1 cannot carry version 2 fields")
	}

	if !s.Scheme.valid() {
		return fmt.Errorf("ingress spec: unknown scheme %q", s.Scheme)
	}

	if s.Scheme.requiresParameter() && s.parameter() == "" {
		return fmt.Errorf("ingress spec: scheme %q requires a parameter name", s.Scheme)
	}

	return s.validateV2Fields()
}

func (s Spec) validateV2Fields() error {
	isHMAC := s.Scheme == SchemeHMACSHA256 || s.Scheme == SchemeHMACSHA1

	if s.Signed != nil {
		if !isHMAC {
			return fmt.Errorf("ingress spec: a signed template needs an HMAC scheme, not %q", s.Scheme)
		}

		if strings.Count(*s.Signed, rawPlaceholder) != 1 {
			return fmt.Errorf("ingress spec: a signed template must contain %s exactly once", rawPlaceholder)
		}
	}

	if s.Timestamp != nil {
		if !isHMAC {
			return fmt.Errorf("ingress spec: a timestamp check needs an HMAC scheme, not %q", s.Scheme)
		}

		if s.Timestamp.Header == "" {
			return fmt.Errorf("ingress spec: a timestamp check needs a header name")
		}

		if s.Timestamp.Tolerance < 1 || s.Timestamp.Tolerance > maxTimestampTolerance {
			return fmt.Errorf("ingress spec: timestamp tolerance must be between 1 and %d seconds", maxTimestampTolerance)
		}
	}

	if s.Form != nil && !formFieldPattern.MatchString(*s.Form) {
		return fmt.Errorf("ingress spec: invalid form field name")
	}

	if s.Handshake != nil && (s.Handshake.When == "" || s.Handshake.Equals == "" || s.Handshake.Respond == "") {
		return fmt.Errorf("ingress spec: a handshake needs when, equals and respond")
	}

	return nil
}

// parameter returns the configured parameter name, or an empty string when absent.
func (s Spec) parameter() string {
	if s.Parameter == nil {
		return ""
	}

	return *s.Parameter
}
