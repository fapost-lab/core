package spec

import (
	"bytes"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
	"time"
)

// goldenPath points at the contract shared with the PHP application. Both suites
// execute this same file; that is the whole mechanism preventing the two
// implementations from drifting apart.
const goldenPath = "../../../contracts/ingress/golden.json"

// goldenFormat is the version of the fixture file itself, independent of the spec wire version.
const goldenFormat = 1

type goldenDocument struct {
	Version int          `json:"version"`
	Cases   []goldenCase `json:"cases"`
}

type goldenCase struct {
	Name      string          `json:"name"`
	Spec      json.RawMessage `json:"spec"`
	Secret    string          `json:"secret"`
	ChannelID string          `json:"channelId"`
	Note      string          `json:"note"`
	Now       *int64          `json:"now"`
	Request   struct {
		RawBody string            `json:"rawBody"`
		Headers map[string]string `json:"headers"`
		Query   map[string]string `json:"query"`
	} `json:"request"`
	Expect struct {
		Verified       bool    `json:"verified"`
		IdempotencyKey *string `json:"idempotencyKey"`

		// Document is compared structurally when present.
		Document json.RawMessage `json:"document"`

		// Handshake is only present when the case pins it; null means "no challenge".
		Handshake json.RawMessage `json:"handshake"`
	} `json:"expect"`
}

func loadGolden(t *testing.T) goldenDocument {
	t.Helper()

	raw, err := os.ReadFile(filepath.Clean(goldenPath))
	if err != nil {
		t.Fatalf("read golden contract: %v", err)
	}

	var document goldenDocument
	if err := json.Unmarshal(raw, &document); err != nil {
		t.Fatalf("parse golden contract: %v", err)
	}

	if len(document.Cases) == 0 {
		t.Fatal("golden contract has no cases; the suite would pass vacuously")
	}

	return document
}

func TestGoldenContract(t *testing.T) {
	document := loadGolden(t)

	if document.Version != goldenFormat {
		t.Fatalf("golden contract version %d, this test understands %d", document.Version, goldenFormat)
	}

	for _, testCase := range document.Cases {
		t.Run(testCase.Name, func(t *testing.T) {
			parsed, err := Parse(testCase.Spec)
			if err != nil {
				t.Fatalf("parse spec: %v", err)
			}

			request := NewRequest(
				[]byte(testCase.Request.RawBody),
				testCase.Request.Headers,
				testCase.Request.Query,
			)

			now := time.Now().Unix()
			if testCase.Now != nil {
				now = *testCase.Now
			} else if parsed.Timestamp != nil {
				t.Fatal("a case whose spec has a timestamp must set now")
			}

			verified := parsed.VerifyAt(request, testCase.Secret, now)
			if verified != testCase.Expect.Verified {
				t.Errorf("VerifyAt() = %v, want %v%s", verified, testCase.Expect.Verified, context(testCase))
			}

			assertDocument(t, testCase, parsed, request)
			assertHandshake(t, testCase, parsed, request, verified)

			if testCase.Expect.IdempotencyKey == nil {
				return
			}

			if got := parsed.IdempotencyKey(request, testCase.ChannelID); got != *testCase.Expect.IdempotencyKey {
				t.Errorf("IdempotencyKey() = %q, want %q%s", got, *testCase.Expect.IdempotencyKey, context(testCase))
			}
		})
	}
}

func assertDocument(t *testing.T, testCase goldenCase, parsed Spec, request *Request) {
	t.Helper()

	if len(testCase.Expect.Document) == 0 {
		return
	}

	want, ok := canonical(testCase.Expect.Document)
	if !ok {
		t.Fatalf("expected document is not JSON: %s", testCase.Expect.Document)
	}

	actual, err := json.Marshal(parsed.Document(request))
	if err != nil {
		t.Fatalf("marshal document: %v", err)
	}

	got, _ := canonical(actual)

	if got != want {
		t.Errorf("Document() = %s, want %s%s", got, want, context(testCase))
	}
}

// canonical renders JSON with sorted keys and with every empty container (and null) as "[]",
// because PHP cannot tell an empty object from an empty list.
func canonical(raw []byte) (string, bool) {
	var value any

	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()

	if err := decoder.Decode(&value); err != nil {
		return "", false
	}

	out, err := json.Marshal(value)
	if err != nil {
		return "", false
	}

	switch string(out) {
	case "null", "{}", "[]":
		return "[]", true
	default:
		return string(out), true
	}
}

func assertHandshake(t *testing.T, testCase goldenCase, parsed Spec, request *Request, verified bool) {
	t.Helper()

	if len(testCase.Expect.Handshake) == 0 {
		return
	}

	var want *string
	if err := json.Unmarshal(testCase.Expect.Handshake, &want); err != nil {
		t.Fatalf("expected handshake must be a string or null: %v", err)
	}

	var got *string

	if verified {
		if answer, ok := parsed.HandshakeResponse(request); ok {
			got = &answer
		}
	}

	switch {
	case got == nil && want != nil:
		t.Errorf("HandshakeResponse() = none, want %q%s", *want, context(testCase))
	case got != nil && want == nil:
		t.Errorf("HandshakeResponse() = %q, want none%s", *got, context(testCase))
	case got != nil && *got != *want:
		t.Errorf("HandshakeResponse() = %q, want %q%s", *got, *want, context(testCase))
	}
}

// TestGoldenCoversEverySupportedScheme fails when this build gains a scheme the
// shared contract does not exercise, which would leave it unverified against PHP.
func TestGoldenCoversEverySupportedScheme(t *testing.T) {
	document := loadGolden(t)

	covered := map[Scheme]bool{}

	for _, testCase := range document.Cases {
		var parsed Spec
		if err := json.Unmarshal(testCase.Spec, &parsed); err != nil {
			t.Fatalf("parse spec for %q: %v", testCase.Name, err)
		}

		covered[parsed.Scheme] = true
	}

	for _, scheme := range []Scheme{
		SchemeNone,
		SchemeHeaderEquals,
		SchemeHMACSHA256,
		SchemeHMACSHA1,
		SchemeQueryParam,
	} {
		if !covered[scheme] {
			t.Errorf("scheme %q has no golden case, so no runtime is held to it", scheme)
		}
	}
}

func context(testCase goldenCase) string {
	if testCase.Note == "" {
		return ""
	}

	return " — " + testCase.Note
}
