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
)

// Version is the wire format this package understands. It matches
// IngressSpec::VERSION on the PHP side and must be bumped in lockstep.
const Version = 1

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
	if s.Version != Version {
		return fmt.Errorf("ingress spec: unsupported version %d (this build speaks %d)", s.Version, Version)
	}

	if !s.Scheme.valid() {
		return fmt.Errorf("ingress spec: unknown scheme %q", s.Scheme)
	}

	if s.Scheme.requiresParameter() && s.parameter() == "" {
		return fmt.Errorf("ingress spec: scheme %q requires a parameter name", s.Scheme)
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
