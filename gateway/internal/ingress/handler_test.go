package ingress

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"net/netip"
	"net/url"
	"strings"
	"sync"
	"testing"
	"time"

	"fapost/gateway/internal/ratelimit"
	"fapost/gateway/internal/registry"
	"fapost/gateway/internal/spec"
)

const (
	testSecret = "channel-secret"
	testHash   = "0123456789abcdef0123456789abcdef"
	testBody   = `{"update_id":987654}`
)

var errRedisDown = errors.New("redis unreachable")

// --- fakes -------------------------------------------------------------------

type fakeChannels struct {
	entry      registry.Entry
	lookupErr  error
	spec       spec.Spec
	specErr    error
	claimed    bool
	claimErr   error
	released   []string
	releaseErr error

	mu sync.Mutex
}

func (f *fakeChannels) Lookup(context.Context, string) (registry.Entry, error) {
	return f.entry, f.lookupErr
}

func (f *fakeChannels) Spec(context.Context, string) (spec.Spec, error) {
	return f.spec, f.specErr
}

func (f *fakeChannels) MarkProcessed(context.Context, string, time.Duration) (bool, error) {
	return f.claimed, f.claimErr
}

func (f *fakeChannels) ReleaseProcessed(_ context.Context, key string) error {
	f.mu.Lock()
	defer f.mu.Unlock()

	f.released = append(f.released, key)

	return f.releaseErr
}

func (f *fakeChannels) releasedKeys() []string {
	f.mu.Lock()
	defer f.mu.Unlock()

	return append([]string(nil), f.released...)
}

type fakePublisher struct {
	err error

	mu   sync.Mutex
	sent []map[string]any
}

func (f *fakePublisher) Push(_ context.Context, _ string, data any) error {
	if f.err != nil {
		return f.err
	}

	f.mu.Lock()
	defer f.mu.Unlock()

	if typed, ok := data.(map[string]any); ok {
		f.sent = append(f.sent, typed)
	}

	return nil
}

func (f *fakePublisher) pushed() []map[string]any {
	f.mu.Lock()
	defer f.mu.Unlock()

	return append([]map[string]any(nil), f.sent...)
}

// --- helpers -----------------------------------------------------------------

type harness struct {
	handler   *Handler
	channels  *fakeChannels
	publisher *fakePublisher
	upstream  *httptest.Server

	mu               sync.Mutex
	upstreamRequests []upstreamRequest
}

type upstreamRequest struct {
	path      string
	body      string
	headers   http.Header
	requestID string
	host      string
}

func newHarness(t *testing.T, configure func(*fakeChannels)) *harness {
	t.Helper()

	h := &harness{
		channels: &fakeChannels{
			entry: registry.Entry{
				TenantID:    "tenant-1",
				AssistantID: "assistant-9",
				ChannelID:   "channel-777",
				Schema:      "main",
				Platform:    "telegram",
				SecretToken: testSecret,
			},
			spec: spec.Spec{
				Version:     spec.Version,
				Scheme:      spec.SchemeHeaderEquals,
				Parameter:   pointer("x-telegram-bot-api-secret-token"),
				Idempotency: "tg:{channel}:{body.update_id}",
			},
			claimed: true,
		},
		publisher: &fakePublisher{},
	}

	if configure != nil {
		configure(h.channels)
	}

	h.upstream = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)

		h.mu.Lock()
		h.upstreamRequests = append(h.upstreamRequests, upstreamRequest{
			path:      r.URL.Path,
			body:      string(body),
			headers:   r.Header.Clone(),
			requestID: r.Header.Get(RequestIDHeader),
			host:      r.Host,
		})
		h.mu.Unlock()

		w.WriteHeader(http.StatusOK)
		_, _ = w.Write([]byte(`{"ok":true,"from":"upstream"}`))
	}))
	t.Cleanup(h.upstream.Close)

	target, err := url.Parse(h.upstream.URL)
	if err != nil {
		t.Fatalf("parse upstream: %v", err)
	}

	h.handler = New(Options{
		Registry:     h.channels,
		Publisher:    h.publisher,
		Limiter:      ratelimit.New(1000, 1000),
		Logger:       slog.New(slog.NewTextHandler(io.Discard, nil)),
		Upstream:     target,
		MaxBodyBytes: 1 << 20,
		DedupTTL:     time.Hour,
	})

	return h
}

func (h *harness) upstreamCalls() []upstreamRequest {
	h.mu.Lock()
	defer h.mu.Unlock()

	return append([]upstreamRequest(nil), h.upstreamRequests...)
}

func (h *harness) deliver(t *testing.T, secret string) *httptest.ResponseRecorder {
	t.Helper()

	request := httptest.NewRequest(http.MethodPost, "/webhook/telegram/"+testHash, strings.NewReader(testBody))
	request.Header.Set("Content-Type", "application/json")

	if secret != "" {
		request.Header.Set("X-Telegram-Bot-Api-Secret-Token", secret)
	}

	recorder := httptest.NewRecorder()
	h.handler.ServeHTTP(recorder, request)

	return recorder
}

func pointer(value string) *string {
	return &value
}

// --- tests -------------------------------------------------------------------

func TestValidDeliveryIsQueued(t *testing.T) {
	h := newHarness(t, nil)

	response := h.deliver(t, testSecret)

	if response.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", response.Code)
	}

	pushed := h.publisher.pushed()
	if len(pushed) != 1 {
		t.Fatalf("pushed %d jobs, want 1", len(pushed))
	}

	job := pushed[0]

	for key, want := range map[string]any{
		"v":              1,
		"tenantId":       "tenant-1",
		"schema":         "main",
		"assistantId":    "assistant-9",
		"channelId":      "channel-777",
		"platform":       "telegram",
		"idempotencyKey": "tg:channel-777:987654",
	} {
		if job[key] != want {
			t.Errorf("job[%s] = %v, want %v", key, job[key], want)
		}
	}

	if len(h.upstreamCalls()) != 0 {
		t.Error("a valid delivery must not reach the application")
	}
}

// The correlation id ties the gateway log line to the worker's, which is the
// only way to follow one webhook across the two processes.
func TestRequestIDIsReturnedAndCarriedIntoTheJob(t *testing.T) {
	h := newHarness(t, nil)

	response := h.deliver(t, testSecret)

	id := response.Header().Get(RequestIDHeader)
	if id == "" {
		t.Fatal("response carries no request id")
	}

	if got := h.publisher.pushed()[0]["requestId"]; got != id {
		t.Errorf("job requestId = %v, want %q", got, id)
	}
}

// Providers retry on non-2xx. Answering 4xx/5xx to a forged signature would turn
// a probe into an amplification loop, so the response is indistinguishable from
// a success and the rejection lives in the log instead.
func TestForgedSignatureIsRejectedSilently(t *testing.T) {
	h := newHarness(t, nil)

	response := h.deliver(t, "forged")

	if response.Code != http.StatusOK {
		t.Errorf("status = %d, want 200", response.Code)
	}

	if len(h.publisher.pushed()) != 0 {
		t.Error("a forged delivery was queued")
	}

	if len(h.upstreamCalls()) != 0 {
		t.Error("a forged delivery was proxied instead of dropped")
	}
}

func TestDuplicateDeliveryIsNotQueuedTwice(t *testing.T) {
	h := newHarness(t, func(c *fakeChannels) { c.claimed = false })

	response := h.deliver(t, testSecret)

	if response.Code != http.StatusOK {
		t.Errorf("status = %d, want 200", response.Code)
	}

	if len(h.publisher.pushed()) != 0 {
		t.Error("a duplicate delivery was queued")
	}
}

// Redis is a cache, not the source of truth. On a miss the application can still
// resolve the channel from its database and repopulate the cache, so the request
// must reach it rather than be dropped.
func TestUnknownChannelIsProxiedRatherThanRejected(t *testing.T) {
	h := newHarness(t, func(c *fakeChannels) { c.lookupErr = registry.ErrNotFound })

	response := h.deliver(t, testSecret)

	calls := h.upstreamCalls()
	if len(calls) != 1 {
		t.Fatalf("upstream calls = %d, want 1", len(calls))
	}

	if calls[0].path != "/webhook/telegram/"+testHash {
		t.Errorf("upstream path = %q, want the original path unchanged", calls[0].path)
	}

	if !strings.Contains(response.Body.String(), "upstream") {
		t.Error("the upstream response was not returned to the caller")
	}
}

func TestRedisFailureIsProxiedRatherThanRejected(t *testing.T) {
	h := newHarness(t, func(c *fakeChannels) { c.lookupErr = errRedisDown })

	h.deliver(t, testSecret)

	if len(h.upstreamCalls()) != 1 {
		t.Fatal("a Redis outage must degrade to the application, not fail the delivery")
	}
}

// A platform whose adapter keeps verification in PHP publishes no spec. That is
// by design, not an error, and those webhooks belong on the PHP path.
func TestPlatformWithoutASpecIsProxied(t *testing.T) {
	h := newHarness(t, func(c *fakeChannels) { c.specErr = registry.ErrNotFound })

	h.deliver(t, testSecret)

	if len(h.upstreamCalls()) != 1 {
		t.Fatal("a platform without a spec must be proxied")
	}

	if len(h.publisher.pushed()) != 0 {
		t.Error("an unverified delivery was queued")
	}
}

func TestClaimFailureIsProxied(t *testing.T) {
	h := newHarness(t, func(c *fakeChannels) { c.claimErr = errRedisDown })

	h.deliver(t, testSecret)

	if len(h.upstreamCalls()) != 1 {
		t.Fatal("a failed idempotency claim must be proxied")
	}
}

// Without releasing the claim the message is lost for good: the provider was told
// the delivery succeeded, nothing reached the queue, and its retry would be
// dismissed as a duplicate.
func TestFailedPublishReleasesTheIdempotencyClaim(t *testing.T) {
	h := newHarness(t, nil)
	h.publisher.err = errRedisDown

	h.deliver(t, testSecret)

	released := h.channels.releasedKeys()
	if len(released) != 1 {
		t.Fatalf("released %d keys, want 1", len(released))
	}

	if released[0] != "tg:channel-777:987654" {
		t.Errorf("released %q, want the claimed key", released[0])
	}

	if len(h.upstreamCalls()) != 1 {
		t.Error("a failed publish must also be proxied so the delivery gets a second chance")
	}
}

// The signature the application re-checks is computed over these exact bytes, so
// the proxy must not touch the body or the headers that carry the signature.
func TestProxyForwardsBodyAndSignatureHeaderUnchanged(t *testing.T) {
	h := newHarness(t, func(c *fakeChannels) { c.lookupErr = registry.ErrNotFound })

	h.deliver(t, testSecret)

	call := h.upstreamCalls()[0]

	if call.body != testBody {
		t.Errorf("upstream body = %q, want %q", call.body, testBody)
	}

	if got := call.headers.Get("X-Telegram-Bot-Api-Secret-Token"); got != testSecret {
		t.Errorf("signature header = %q, want it forwarded verbatim", got)
	}

	if call.requestID == "" {
		t.Error("the correlation id was not forwarded upstream")
	}
}

func proxiedHost(t *testing.T, preserve bool) (upstreamHost, upstreamURLHost string) {
	t.Helper()

	h := newHarness(t, func(c *fakeChannels) { c.lookupErr = registry.ErrNotFound })
	h.handler.options.UpstreamPreserveHost = preserve

	request := httptest.NewRequest(http.MethodPost, "/webhook/telegram/"+testHash, strings.NewReader(testBody))
	request.Host = "gateway.fapost.example.com"
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("X-Telegram-Bot-Api-Secret-Token", testSecret)

	h.handler.ServeHTTP(httptest.NewRecorder(), request)

	calls := h.upstreamCalls()
	if len(calls) != 1 {
		t.Fatalf("upstream calls = %d, want 1", len(calls))
	}

	return calls[0].host, h.handler.options.Upstream.Host
}

// Host tenancy mode answers only hosts under its base domain, so an internal upstream can opt in to the
// caller's Host on the fallback.
func TestProxyPreservesTheOriginalHostWhenOptedIn(t *testing.T) {
	got, _ := proxiedHost(t, true)

	if got != "gateway.fapost.example.com" {
		t.Errorf("upstream Host = %q, want the original host", got)
	}
}

// A public upstream URL behind the proxy that fronts the gateway would route the gateway's own Host back to
// the gateway: by default the upstream URL's Host is kept.
func TestProxyKeepsTheUpstreamHostByDefault(t *testing.T) {
	got, want := proxiedHost(t, false)

	if got != want {
		t.Errorf("upstream Host = %q, want the upstream URL's %q", got, want)
	}
}

// The URL ends in the webhook hash, which is the credential for a channel, and
// url.Error embeds the whole URL in its message. Logging such an error verbatim
// would publish the value the fingerprinting exists to keep out of the logs.
func TestTransportErrorDropsTheRequestURL(t *testing.T) {
	wrapped := &url.Error{
		Op:  "Post",
		URL: "https://app.example.com/webhook/telegram/" + testHash,
		Err: errors.New("connection refused"),
	}

	rendered := transportError(wrapped)

	if strings.Contains(rendered, testHash) {
		t.Fatalf("transportError leaked the webhook hash: %q", rendered)
	}

	if !strings.Contains(rendered, "connection refused") {
		t.Errorf("transportError = %q, want the underlying cause preserved", rendered)
	}
}

func TestUnreachableUpstreamIsLoggedWithoutTheHash(t *testing.T) {
	var captured strings.Builder

	h := newHarness(t, func(c *fakeChannels) { c.lookupErr = registry.ErrNotFound })
	h.handler.options.Logger = slog.New(slog.NewJSONHandler(&captured, nil))

	// Point at a closed port so the client fails and the error path is taken.
	unreachable, err := url.Parse("http://127.0.0.1:9")
	if err != nil {
		t.Fatalf("parse: %v", err)
	}

	h.handler.options.Upstream = unreachable
	h.handler.options.Client = &http.Client{Timeout: time.Second}

	h.deliver(t, testSecret)

	if strings.Contains(captured.String(), testHash) {
		t.Errorf("log leaked the webhook hash: %s", captured.String())
	}

	if !strings.Contains(captured.String(), "upstream_host") {
		t.Error("log does not identify the upstream host")
	}
}

func TestRateLimitedRequestIsNotQueued(t *testing.T) {
	h := newHarness(t, nil)
	h.handler.options.Limiter = ratelimit.New(1, 1)

	first := h.deliver(t, testSecret)
	second := h.deliver(t, testSecret)

	if first.Code != http.StatusOK || second.Code != http.StatusOK {
		t.Error("providers must always see 200")
	}

	if len(h.publisher.pushed()) != 1 {
		t.Errorf("pushed %d jobs, want 1 — the second was over the limit", len(h.publisher.pushed()))
	}
}

func TestNonWebhookPathsAreNotFound(t *testing.T) {
	h := newHarness(t, nil)

	for _, path := range []string{"/", "/webhook", "/webhook/telegram", "/something/else"} {
		recorder := httptest.NewRecorder()
		h.handler.ServeHTTP(recorder, httptest.NewRequest(http.MethodPost, path, nil))

		if recorder.Code != http.StatusNotFound {
			t.Errorf("%s: status = %d, want 404", path, recorder.Code)
		}
	}
}

func TestGetIsNotTreatedAsADelivery(t *testing.T) {
	h := newHarness(t, nil)

	recorder := httptest.NewRecorder()
	h.handler.ServeHTTP(recorder, httptest.NewRequest(http.MethodGet, "/webhook/telegram/"+testHash, nil))

	if recorder.Code != http.StatusNotFound {
		t.Errorf("status = %d, want 404", recorder.Code)
	}
}

func TestOversizedBodyIsRejectedWithoutProxying(t *testing.T) {
	h := newHarness(t, nil)
	h.handler.options.MaxBodyBytes = 8

	request := httptest.NewRequest(http.MethodPost, "/webhook/telegram/"+testHash, strings.NewReader(testBody))
	request.Header.Set("X-Telegram-Bot-Api-Secret-Token", testSecret)

	recorder := httptest.NewRecorder()
	h.handler.ServeHTTP(recorder, request)

	if len(h.upstreamCalls()) != 0 {
		t.Error("an oversized body was forwarded; it would fail the same way in PHP")
	}

	if len(h.publisher.pushed()) != 0 {
		t.Error("an oversized body was queued")
	}
}

// A body that verified but is not a JSON object still has to reach the worker in
// a shape it can decode, rather than failing the job on the far side.
func TestNonObjectBodyIsQueuedWithAnEmptyPayload(t *testing.T) {
	h := newHarness(t, func(c *fakeChannels) {
		c.spec = spec.Spec{Version: spec.Version, Scheme: spec.SchemeNone, Idempotency: "k:{channel}"}
	})

	request := httptest.NewRequest(http.MethodPost, "/webhook/telegram/"+testHash, strings.NewReader(`[1,2,3]`))
	recorder := httptest.NewRecorder()
	h.handler.ServeHTTP(recorder, request)

	pushed := h.publisher.pushed()
	if len(pushed) != 1 {
		t.Fatalf("pushed %d jobs, want 1", len(pushed))
	}

	raw, ok := pushed[0]["rawPayload"].(map[string]any)
	if !ok {
		t.Fatalf("rawPayload = %v, want an object", pushed[0]["rawPayload"])
	}

	if len(raw) != 0 {
		t.Errorf("rawPayload = %v, want empty", raw)
	}
}

// Trusting the header from any peer would let a caller forge an address and walk
// straight around the per-client limit.
func TestForwardedForIsIgnoredFromAnUntrustedPeer(t *testing.T) {
	h := newHarness(t, nil)

	request := httptest.NewRequest(http.MethodPost, "/webhook/telegram/"+testHash, strings.NewReader(testBody))
	request.RemoteAddr = "203.0.113.9:5555"
	request.Header.Set("X-Forwarded-For", "198.51.100.1")

	if got := h.handler.clientIP(request); got != "203.0.113.9" {
		t.Errorf("clientIP = %q, want the peer address", got)
	}
}

// The two entry forms an operator may configure, and the peer that neither of
// them covers. A range matters more than the exact address: behind Docker
// Compose the proxy's address is assigned by the network driver and changes on
// every recreate, so a subnet is the only value that survives a restart.
func TestForwardedForIsHonouredOnlyFromAConfiguredNetwork(t *testing.T) {
	exact := netip.PrefixFrom(netip.MustParseAddr("10.0.0.5"), 32)
	composeSubnet := netip.MustParsePrefix("172.16.0.0/12")

	cases := map[string]struct {
		trusted []netip.Prefix
		peer    string
		want    string
	}{
		"exact address": {
			trusted: []netip.Prefix{exact},
			peer:    "10.0.0.5:5555",
			want:    "198.51.100.1",
		},
		"inside a range": {
			trusted: []netip.Prefix{composeSubnet},
			peer:    "172.19.0.7:5555",
			want:    "198.51.100.1",
		},
		"outside the range": {
			trusted: []netip.Prefix{composeSubnet},
			peer:    "203.0.113.9:5555",
			want:    "203.0.113.9",
		},
	}

	for name, testCase := range cases {
		t.Run(name, func(t *testing.T) {
			h := newHarness(t, nil)
			h.handler.options.TrustedProxies = testCase.trusted

			request := httptest.NewRequest(http.MethodPost, "/webhook/telegram/"+testHash, strings.NewReader(testBody))
			request.RemoteAddr = testCase.peer
			request.Header.Set("X-Forwarded-For", "198.51.100.1, 10.0.0.5")

			if got := h.handler.clientIP(request); got != testCase.want {
				t.Errorf("clientIP = %q, want %q", got, testCase.want)
			}
		})
	}
}

// Whatever the outcome, the provider sees the same body: naming the failing check
// would tell a prober exactly which step to work on.
func TestResponseBodyIsIdenticalAcrossOutcomes(t *testing.T) {
	cases := map[string]func(*fakeChannels){
		"queued":    nil,
		"forged":    nil,
		"duplicate": func(c *fakeChannels) { c.claimed = false },
	}

	bodies := map[string]string{}

	for name, configure := range cases {
		h := newHarness(t, configure)

		secret := testSecret
		if name == "forged" {
			secret = "forged"
		}

		bodies[name] = h.deliver(t, secret).Body.String()
	}

	var reference string
	for name, body := range bodies {
		if reference == "" {
			reference = body

			continue
		}

		if body != reference {
			t.Errorf("%s body = %q, want %q — outcomes must be indistinguishable", name, body, reference)
		}
	}

	var decoded map[string]any
	if err := json.Unmarshal([]byte(reference), &decoded); err != nil {
		t.Fatalf("response is not JSON: %v", err)
	}

	if decoded["ok"] != true {
		t.Errorf("response = %v, want {\"ok\":true}", decoded)
	}
}
