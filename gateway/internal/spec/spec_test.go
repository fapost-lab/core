package spec

import (
	"errors"
	"testing"
)

// The golden contract covers execution of valid specs. These cases cover the
// rejection paths, which matter most: a spec this build cannot execute faithfully
// must be refused outright so the caller falls back to the PHP ingress, rather
// than being executed approximately.
func TestParseRejectsUnexecutableSpecs(t *testing.T) {
	cases := []struct {
		name string
		json string
	}{
		{
			name: "future version",
			json: `{"v":3,"scheme":"none","parameter":null,"prefix":"","idempotency":"k"}`,
		},
		{
			name: "version 1 with a signed template",
			json: `{"v":1,"scheme":"hmac_sha256","parameter":"x","prefix":"","idempotency":"k","signed":"{raw}"}`,
		},
		{
			name: "version 1 with a form field",
			json: `{"v":1,"scheme":"none","parameter":null,"prefix":"","idempotency":"k","form":"payload"}`,
		},
		{
			name: "version 1 with a timestamp",
			json: `{"v":1,"scheme":"hmac_sha256","parameter":"x","prefix":"","idempotency":"k","timestamp":{"header":"t","tolerance":5}}`,
		},
		{
			name: "version 1 with a handshake",
			json: `{"v":1,"scheme":"none","parameter":null,"prefix":"","idempotency":"k","handshake":{"when":"{body.a}","equals":"b","respond":"c"}}`,
		},
		{
			name: "signed template without raw",
			json: `{"v":2,"scheme":"hmac_sha256","parameter":"x","prefix":"","idempotency":"k","signed":"v0:{header.t}"}`,
		},
		{
			name: "signed template with raw twice",
			json: `{"v":2,"scheme":"hmac_sha256","parameter":"x","prefix":"","idempotency":"k","signed":"{raw}{raw}"}`,
		},
		{
			name: "signed template on a non HMAC scheme",
			json: `{"v":2,"scheme":"header_equals","parameter":"x","prefix":"","idempotency":"k","signed":"{raw}"}`,
		},
		{
			name: "timestamp on a non HMAC scheme",
			json: `{"v":2,"scheme":"none","parameter":null,"prefix":"","idempotency":"k","timestamp":{"header":"t","tolerance":5}}`,
		},
		{
			name: "timestamp tolerance zero",
			json: `{"v":2,"scheme":"hmac_sha256","parameter":"x","prefix":"","idempotency":"k","timestamp":{"header":"t","tolerance":0}}`,
		},
		{
			name: "timestamp tolerance too large",
			json: `{"v":2,"scheme":"hmac_sha256","parameter":"x","prefix":"","idempotency":"k","timestamp":{"header":"t","tolerance":3601}}`,
		},
		{
			name: "timestamp tolerance not an integer",
			json: `{"v":2,"scheme":"hmac_sha256","parameter":"x","prefix":"","idempotency":"k","timestamp":{"header":"t","tolerance":5.5}}`,
		},
		{
			name: "timestamp without a header",
			json: `{"v":2,"scheme":"hmac_sha256","parameter":"x","prefix":"","idempotency":"k","timestamp":{"header":"","tolerance":5}}`,
		},
		{
			name: "form field with a forbidden character",
			json: `{"v":2,"scheme":"none","parameter":null,"prefix":"","idempotency":"k","form":"pay load"}`,
		},
		{
			name: "form field with a trailing newline",
			json: `{"v":2,"scheme":"none","parameter":null,"prefix":"","idempotency":"k","form":"payload\n"}`,
		},
		{
			name: "empty form field",
			json: `{"v":2,"scheme":"none","parameter":null,"prefix":"","idempotency":"k","form":""}`,
		},
		{
			name: "handshake with an empty respond",
			json: `{"v":2,"scheme":"none","parameter":null,"prefix":"","idempotency":"k","handshake":{"when":"{body.a}","equals":"b","respond":""}}`,
		},
		{
			name: "handshake with a non string part",
			json: `{"v":2,"scheme":"none","parameter":null,"prefix":"","idempotency":"k","handshake":{"when":"{body.a}","equals":1,"respond":"c"}}`,
		},
		{
			name: "signed template that is not a string",
			json: `{"v":2,"scheme":"hmac_sha256","parameter":"x","prefix":"","idempotency":"k","signed":5}`,
		},
		{
			name: "missing version",
			json: `{"scheme":"none","parameter":null,"prefix":"","idempotency":"k"}`,
		},
		{
			name: "unknown scheme",
			json: `{"v":1,"scheme":"hmac_sha512","parameter":"x","prefix":"","idempotency":"k"}`,
		},
		{
			name: "scheme without required parameter",
			json: `{"v":1,"scheme":"header_equals","parameter":null,"prefix":"","idempotency":"k"}`,
		},
		{
			name: "scheme with empty parameter",
			json: `{"v":1,"scheme":"header_equals","parameter":"","prefix":"","idempotency":"k"}`,
		},
		{
			name: "malformed json",
			json: `{"v":1,`,
		},
	}

	for _, testCase := range cases {
		t.Run(testCase.name, func(t *testing.T) {
			if _, err := Parse([]byte(testCase.json)); err == nil {
				t.Fatal("expected the spec to be rejected, got no error")
			}
		})
	}
}

// An unknown scheme reaching Verify must deny. Treating it as SchemeNone would
// turn a spec this build does not understand into a waved-through request.
func TestVerifyDeniesUnknownScheme(t *testing.T) {
	unknown := Spec{Version: Version, Scheme: Scheme("hmac_sha512"), Prefix: "", Idempotency: "k"}

	if unknown.Verify(NewRequest([]byte("{}"), nil, nil), "secret") {
		t.Fatal("unknown scheme verified the request")
	}
}

func TestNewRequestTolueratesNilMaps(t *testing.T) {
	request := NewRequest([]byte("{}"), nil, nil)

	if got := request.Header("x-any"); got != "" {
		t.Errorf("Header() = %q, want empty", got)
	}

	if got := request.QueryParam("any"); got != "" {
		t.Errorf("QueryParam() = %q, want empty", got)
	}
}

// The decoded body is cached, so repeated placeholder lookups must not re-parse
// or, worse, consume the reader and start returning nothing.
func TestBodyDecodingIsStableAcrossLookups(t *testing.T) {
	spec := Spec{
		Version:     Version,
		Scheme:      SchemeNone,
		Idempotency: "{body.a}:{body.a}:{body.b}",
	}

	request := NewRequest([]byte(`{"a":1,"b":"x"}`), nil, nil)

	if got, want := spec.IdempotencyKey(request, "c"), "1:1:x"; got != want {
		t.Fatalf("IdempotencyKey() = %q, want %q", got, want)
	}

	if got, want := spec.IdempotencyKey(request, "c"), "1:1:x"; got != want {
		t.Fatalf("second call = %q, want %q", got, want)
	}
}

func TestParseAcceptsBothWireVersions(t *testing.T) {
	cases := map[string]string{
		"version 1":                 `{"v":1,"scheme":"header_equals","parameter":"x","prefix":"","idempotency":"k","signed":null,"timestamp":null,"form":null,"handshake":null}`,
		"version 1 without v2 keys": `{"v":1,"scheme":"none","parameter":null,"prefix":"","idempotency":"k"}`,
		"version 2 with every key": `{"v":2,"scheme":"hmac_sha256","parameter":"x","prefix":"v0=","idempotency":"{body.a|body.b}",` +
			`"signed":"v0:{header.t}:{raw}","timestamp":{"header":"t","tolerance":300},"form":"payload",` +
			`"handshake":{"when":"{body.type}","equals":"url_verification","respond":"{body.challenge}"}}`,
		"version 2 with null keys": `{"v":2,"scheme":"none","parameter":null,"prefix":"","idempotency":"{body.a|body.b}","signed":null,"timestamp":null,"form":null,"handshake":null}`,
	}

	for name, payload := range cases {
		t.Run(name, func(t *testing.T) {
			if _, err := Parse([]byte(payload)); err != nil {
				t.Fatalf("unexpected rejection: %v", err)
			}
		})
	}
}

func TestVerifyAtDeniesWhenTheTimestampSpecIsUnsatisfiable(t *testing.T) {
	parsed, err := Parse([]byte(`{"v":2,"scheme":"hmac_sha256","parameter":"x","prefix":"","idempotency":"k","timestamp":{"header":"t","tolerance":10}}`))
	if err != nil {
		t.Fatal(err)
	}

	request := NewRequest([]byte("{}"), map[string]string{"x": "irrelevant"}, nil)

	if err := parsed.Check(request, "secret", 1000); !errors.Is(err, ErrTimestamp) {
		t.Fatalf("Check() = %v, want ErrTimestamp", err)
	}

	if err := parsed.Check(request, "", 1000); !errors.Is(err, ErrSignature) {
		t.Fatalf("Check() with no secret = %v, want ErrSignature", err)
	}
}
