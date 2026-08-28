// Package ingress implements the gateway's HTTP surface.
//
// The handler answers 200 to the provider in every outcome that is not the
// provider's fault. Telegram and Meta retry on non-2xx, so a 500 caused by our
// own Redis being down would multiply the traffic exactly when we can least
// absorb it; the same reasoning is already baked into the PHP controller, which
// returns ok even for an invalid signature.
//
// Anything the gateway cannot decide for itself is proxied to the application
// rather than rejected. Redis is a cache, and a cold or unreachable cache must
// degrade throughput, never availability.
package ingress

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/netip"
	"net/url"
	"strings"
	"time"

	"fapost/gateway/internal/logging"
	"fapost/gateway/internal/ratelimit"
	"fapost/gateway/internal/registry"
	"fapost/gateway/internal/spec"
)

// RequestIDHeader carries the correlation id to the application and back to the caller.
const RequestIDHeader = "X-Request-Id"

// Options configures the handler.
type Options struct {
	Registry     Channels
	Publisher    Publisher
	Limiter      *ratelimit.Limiter
	Logger       *slog.Logger
	Upstream     *url.URL
	Client       *http.Client
	MaxBodyBytes int64
	DedupTTL     time.Duration

	// TrustedProxies are the networks whose X-Forwarded-For may be believed.
	// A single address is expressed as the range containing only itself, which
	// config does when resolving the setting.
	TrustedProxies []netip.Prefix
}

// Channels is the registry surface the handler needs.
//
// An interface rather than the concrete registry so the decision logic — which
// outcomes proxy, which reject, when a claim is released — can be exercised
// against every failure mode without a live Redis to arrange them in.
type Channels interface {
	Lookup(ctx context.Context, hash string) (registry.Entry, error)
	Spec(ctx context.Context, platform string) (spec.Spec, error)
	MarkProcessed(ctx context.Context, key string, ttl time.Duration) (bool, error)
	ReleaseProcessed(ctx context.Context, key string) error
}

// Publisher enqueues an accepted webhook.
type Publisher interface {
	Push(ctx context.Context, id string, data any) error
}

// Handler serves webhook deliveries.
type Handler struct {
	options Options
}

// New builds the handler.
func New(options Options) *Handler {
	if options.Client == nil {
		options.Client = &http.Client{Timeout: 15 * time.Second}
	}

	return &Handler{options: options}
}

// outcome names why a request ended the way it did. It is logged, not returned
// to the caller: the provider always sees the same body, and telling an attacker
// which check rejected them is free reconnaissance.
type outcome string

const (
	outcomeQueued       outcome = "queued"
	outcomeDuplicate    outcome = "duplicate"
	outcomeBadSignature outcome = "bad_signature"
	outcomeRateLimited  outcome = "rate_limited"
	outcomeProxied      outcome = "proxied"
	outcomeRejected     outcome = "rejected"
)

func (h *Handler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	started := time.Now()
	requestID := newRequestID()

	w.Header().Set(RequestIDHeader, requestID)

	platform, hash, ok := parsePath(r.URL.Path)
	if !ok || r.Method != http.MethodPost {
		// Not a webhook delivery at all; no reason to involve the application.
		h.log(r, requestID, outcomeRejected, "unroutable request", started,
			slog.String("method", r.Method), slog.String("path", r.URL.Path))
		http.NotFound(w, r)

		return
	}

	logger := h.options.Logger.With(
		slog.String("request_id", requestID),
		slog.String("platform", platform),
		slog.String("channel", logging.Fingerprint(hash)),
	)

	body, err := h.readBody(w, r)
	if err != nil {
		// Oversized or truncated bodies are the caller's problem, and proxying
		// them would just move the same failure into PHP.
		logger.Warn("rejected oversized or unreadable body",
			slog.String("outcome", string(outcomeRejected)),
			slog.String("error", err.Error()))
		writeOK(w)

		return
	}

	if !h.options.Limiter.Allow(hash) {
		logger.Warn("rate limited",
			slog.String("outcome", string(outcomeRateLimited)),
			slog.String("client_ip", h.clientIP(r)),
			slog.Int("body_bytes", len(body)))
		writeOK(w)

		return
	}

	h.dispatch(w, r, dispatchContext{
		requestID: requestID,
		platform:  platform,
		hash:      hash,
		body:      body,
		logger:    logger,
		started:   started,
	})
}

type dispatchContext struct {
	requestID string
	platform  string
	hash      string
	body      []byte
	logger    *slog.Logger
	started   time.Time
}

func (h *Handler) dispatch(w http.ResponseWriter, r *http.Request, dc dispatchContext) {
	entry, err := h.options.Registry.Lookup(r.Context(), dc.hash)
	if err != nil {
		// Absent means the application may still know this channel and will
		// repopulate Redis; unreadable means Redis is unwell. Both are handled
		// by the application, which owns the source of truth.
		h.proxy(w, r, dc, "registry lookup failed", err)

		return
	}

	ingressSpec, err := h.options.Registry.Spec(r.Context(), entry.Platform)
	if err != nil {
		// No spec means this platform is verified by PHP code, by design.
		h.proxy(w, r, dc, "no executable ingress spec", err)

		return
	}

	signed := spec.NewRequest(dc.body, flatten(r.Header), flattenQuery(r.URL.Query()))

	if !ingressSpec.Verify(signed, entry.SecretToken) {
		// Logged at warn: a burst of these is either an attacker probing a known
		// hash or a secret that drifted between the provider and the registry.
		dc.logger.Warn("signature rejected",
			slog.String("outcome", string(outcomeBadSignature)),
			slog.String("client_ip", h.clientIP(r)),
			slog.Duration("took", time.Since(dc.started)))
		writeOK(w)

		return
	}

	key := ingressSpec.IdempotencyKey(signed, entry.ChannelID)

	claimed, err := h.options.Registry.MarkProcessed(r.Context(), key, h.options.DedupTTL)
	if err != nil {
		h.proxy(w, r, dc, "idempotency claim failed", err)

		return
	}

	if !claimed {
		dc.logger.Info("duplicate delivery ignored",
			slog.String("outcome", string(outcomeDuplicate)),
			slog.Duration("took", time.Since(dc.started)))
		writeOK(w)

		return
	}

	if err := h.publish(r.Context(), dc, entry, key); err != nil {
		// The claim is released inside publish, so the provider's retry is not
		// swallowed as a duplicate. Proxying gives this delivery a second chance
		// right now rather than relying on that retry.
		h.proxy(w, r, dc, "queue publish failed", err)

		return
	}

	dc.logger.Info("webhook queued",
		slog.String("outcome", string(outcomeQueued)),
		slog.String("tenant", entry.TenantID),
		slog.Int("body_bytes", len(dc.body)),
		slog.Duration("took", time.Since(dc.started)))

	writeOK(w)
}

func (h *Handler) publish(ctx context.Context, dc dispatchContext, entry registry.Entry, key string) error {
	var raw map[string]any

	// The worker expects a decoded object. A body that verified but is not a JSON
	// object cannot be normalized downstream, so it travels as an empty payload
	// rather than failing the job on the far side.
	if err := json.Unmarshal(dc.body, &raw); err != nil {
		raw = map[string]any{}
	}

	data := map[string]any{
		"v":              1,
		"tenantId":       entry.TenantID,
		"schema":         entry.Schema,
		"assistantId":    entry.AssistantID,
		"channelId":      entry.ChannelID,
		"platform":       entry.Platform,
		"rawPayload":     raw,
		"idempotencyKey": key,
		"receivedAt":     dc.started.Unix(),
		"requestId":      dc.requestID,
	}

	if err := h.options.Publisher.Push(ctx, dc.requestID, data); err != nil {
		if releaseErr := h.options.Registry.ReleaseProcessed(ctx, key); releaseErr != nil {
			dc.logger.Error("could not release idempotency key after a failed publish",
				slog.String("error", releaseErr.Error()))
		}

		return err
	}

	return nil
}

// proxy forwards the request to the application's own ingress.
//
// This is the path that keeps a cold or broken Redis from costing availability:
// the application reads its landlord database, repopulates the cache, and serves
// the request. Throughput drops to what PHP alone can do — which is what the
// system did before this gateway existed.
func (h *Handler) proxy(w http.ResponseWriter, r *http.Request, dc dispatchContext, why string, cause error) {
	target := *h.options.Upstream
	target.Path = strings.TrimSuffix(target.Path, "/") + r.URL.Path
	target.RawQuery = r.URL.RawQuery

	request, err := http.NewRequestWithContext(r.Context(), r.Method, target.String(), bytes.NewReader(dc.body))
	if err != nil {
		dc.logger.Error("could not build upstream request",
			slog.String("outcome", string(outcomeRejected)),
			slog.String("reason", why),
			slog.String("upstream_host", target.Host),
			slog.String("error", transportError(err)))
		writeOK(w)

		return
	}

	// Headers are copied verbatim: the signature the application will check is
	// computed over headers and raw body, so altering either breaks verification.
	request.Header = r.Header.Clone()
	request.Header.Set(RequestIDHeader, dc.requestID)
	request.Header.Set("X-Forwarded-For", h.clientIP(r))
	request.Header.Set("X-Forwarded-Host", r.Host)
	request.Header.Set("X-Forwarded-Proto", scheme(r))

	response, err := h.options.Client.Do(request)
	if err != nil {
		dc.logger.Error("upstream unreachable",
			slog.String("outcome", string(outcomeRejected)),
			slog.String("reason", why),
			slog.String("upstream_host", target.Host),
			slog.String("error", transportError(err)),
			slog.Duration("took", time.Since(dc.started)))
		writeOK(w)

		return
	}
	defer response.Body.Close()

	attributes := []any{
		slog.String("outcome", string(outcomeProxied)),
		slog.String("reason", why),
		slog.Int("upstream_status", response.StatusCode),
		slog.Duration("took", time.Since(dc.started)),
	}

	if cause != nil && !errors.Is(cause, registry.ErrNotFound) {
		attributes = append(attributes, slog.String("error", cause.Error()))
	}

	dc.logger.Info("proxied to application", attributes...)

	for name, values := range response.Header {
		for _, value := range values {
			w.Header().Add(name, value)
		}
	}

	w.WriteHeader(response.StatusCode)
	_, _ = io.Copy(w, response.Body)
}

// readBody reads the whole body under a hard size cap.
//
// The body must be buffered because it is needed three times: to verify the
// signature over the exact bytes received, to extract the deduplication key, and
// possibly to replay upstream.
func (h *Handler) readBody(w http.ResponseWriter, r *http.Request) ([]byte, error) {
	r.Body = http.MaxBytesReader(w, r.Body, h.options.MaxBodyBytes)

	return io.ReadAll(r.Body)
}

// clientIP resolves the caller's address, honouring X-Forwarded-For only from a
// configured proxy.
//
// Trusting the header unconditionally would let any caller forge an address and
// walk around the rate limiter, so an untrusted peer's header is ignored outright.
func (h *Handler) clientIP(r *http.Request) string {
	peer, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		peer = r.RemoteAddr
	}

	if !h.trusts(peer) {
		return peer
	}

	forwarded := r.Header.Get("X-Forwarded-For")
	if forwarded == "" {
		return peer
	}

	// Left-most entry is the original client; the rest are intermediaries.
	if comma := strings.IndexByte(forwarded, ','); comma >= 0 {
		forwarded = forwarded[:comma]
	}

	return strings.TrimSpace(forwarded)
}

// trusts reports whether the peer falls inside one of the configured networks.
//
// Ranges rather than exact addresses because the proxy in front of the gateway
// usually has no fixed address: on a compose network Docker hands one out and
// changes it whenever the container is recreated, leaving the subnet as the only
// thing an operator can name in advance.
func (h *Handler) trusts(peer string) bool {
	if len(h.options.TrustedProxies) == 0 {
		return false
	}

	address, err := netip.ParseAddr(peer)
	if err != nil {
		return false
	}

	// A v4-mapped v6 peer ("::ffff:10.0.0.5") is the same host as its v4 form, and
	// a link-local zone describes the local interface rather than the network, so
	// neither may keep a configured range from matching.
	address = address.Unmap().WithZone("")

	for _, prefix := range h.options.TrustedProxies {
		if prefix.Contains(address) {
			return true
		}
	}

	return false
}

func (h *Handler) log(r *http.Request, requestID string, result outcome, message string, started time.Time, extra ...any) {
	attributes := append([]any{
		slog.String("request_id", requestID),
		slog.String("outcome", string(result)),
		slog.String("client_ip", h.clientIP(r)),
		slog.Duration("took", time.Since(started)),
	}, extra...)

	h.options.Logger.Warn(message, attributes...)
}

// transportError renders an HTTP client failure without the request URL.
//
// url.Error embeds the full URL in its message, and our URLs end in the webhook
// public hash — the credential for a channel. Logging the error verbatim would
// therefore publish the very value Fingerprint exists to keep out of the logs.
// The wrapped cause carries the diagnostic part ("connection refused", "timeout")
// without the address.
func transportError(err error) string {
	var urlError *url.Error

	if errors.As(err, &urlError) && urlError.Err != nil {
		return urlError.Err.Error()
	}

	return err.Error()
}

// parsePath extracts the platform and hash from /webhook/{platform}/{hash}.
//
// The path is identical on both ingress runtimes, which is what lets a proxied
// request be forwarded unchanged.
func parsePath(path string) (string, string, bool) {
	segments := strings.Split(strings.Trim(path, "/"), "/")

	if len(segments) != 3 || segments[0] != "webhook" || segments[1] == "" || segments[2] == "" {
		return "", "", false
	}

	return segments[1], segments[2], true
}

// flatten reduces headers to one value per name, which is what a spec addresses.
func flatten(header http.Header) map[string]string {
	flat := make(map[string]string, len(header))

	for name, values := range header {
		if len(values) > 0 {
			flat[name] = values[0]
		}
	}

	return flat
}

func flattenQuery(query url.Values) map[string]string {
	flat := make(map[string]string, len(query))

	for name, values := range query {
		if len(values) > 0 {
			flat[name] = values[0]
		}
	}

	return flat
}

func scheme(r *http.Request) string {
	if r.TLS != nil {
		return "https"
	}

	if forwarded := r.Header.Get("X-Forwarded-Proto"); forwarded != "" {
		return forwarded
	}

	return "http"
}

// writeOK answers the provider. The body matches the PHP controller's so the two
// ingress paths are indistinguishable from the provider's side.
func writeOK(w http.ResponseWriter) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write([]byte(`{"ok":true}`))
}

func newRequestID() string {
	buffer := make([]byte, 16)

	if _, err := rand.Read(buffer); err != nil {
		// crypto/rand failing is not recoverable in a meaningful way here, and a
		// missing correlation id must not cost us the delivery.
		return "unknown"
	}

	return hex.EncodeToString(buffer)
}
