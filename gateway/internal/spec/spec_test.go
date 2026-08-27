package spec

import "testing"

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
			json: `{"v":2,"scheme":"none","parameter":null,"prefix":"","idempotency":"k"}`,
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
