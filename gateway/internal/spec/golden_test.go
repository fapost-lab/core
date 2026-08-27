package spec

import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

// goldenPath points at the contract shared with the PHP application. Both suites
// execute this same file; that is the whole mechanism preventing the two
// implementations from drifting apart.
const goldenPath = "../../../contracts/ingress/golden.json"

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
	Request   struct {
		RawBody string            `json:"rawBody"`
		Headers map[string]string `json:"headers"`
		Query   map[string]string `json:"query"`
	} `json:"request"`
	Expect struct {
		Verified       bool    `json:"verified"`
		IdempotencyKey *string `json:"idempotencyKey"`
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

	if document.Version != Version {
		t.Fatalf("golden contract version %d, this build speaks %d", document.Version, Version)
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

			if got := parsed.Verify(request, testCase.Secret); got != testCase.Expect.Verified {
				t.Errorf("Verify() = %v, want %v%s", got, testCase.Expect.Verified, context(testCase))
			}

			if testCase.Expect.IdempotencyKey == nil {
				return
			}

			if got := parsed.IdempotencyKey(request, testCase.ChannelID); got != *testCase.Expect.IdempotencyKey {
				t.Errorf("IdempotencyKey() = %q, want %q%s", got, *testCase.Expect.IdempotencyKey, context(testCase))
			}
		})
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
