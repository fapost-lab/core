// Package registry reads channel routing and ingress specs from Redis.
//
// Redis is a cache here, never the source of truth: the application owns that in
// its landlord database and repopulates Redis on a miss. Every lookup therefore
// distinguishes "absent" from "failed", and both are reported to the caller as a
// reason to fall back to the application rather than to reject the request.
package registry

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"sync"
	"time"

	"github.com/redis/go-redis/v9"

	"fapost/gateway/internal/spec"
)

// ErrNotFound reports that a key is absent, as opposed to unreadable.
var ErrNotFound = errors.New("registry: entry not found")

// Entry is a channel's routing envelope, as published by the application.
//
// Field names follow WebhookRegistryEntry::toRedisPayload(). Note that the
// platform is stored under "channel" — matching the application, not renaming it
// here, because a mismatch would read as an unknown platform on every request.
type Entry struct {
	TenantID    string `json:"tenant_id"`
	AssistantID string `json:"assistant_id"`
	ChannelID   string `json:"channel_id"`
	Schema      string `json:"schema"`
	Platform    string `json:"channel"`
	SecretToken string `json:"secret_token"`
}

// valid reports whether the entry carries everything the worker needs.
//
// A half-written entry must be treated as a miss: proxying lets the application
// rebuild it from the database, whereas queueing it would hand the worker a job
// it cannot complete.
func (e Entry) valid() bool {
	return e.TenantID != "" && e.AssistantID != "" && e.ChannelID != "" && e.Schema != "" && e.Platform != ""
}

// Registry reads routing entries and ingress specs.
type Registry struct {
	client  redis.UniversalClient
	timeout time.Duration

	specTTL   time.Duration
	specMu    sync.RWMutex
	specCache map[string]cachedSpec
}

type cachedSpec struct {
	spec      spec.Spec
	found     bool
	expiresAt time.Time
}

// New builds a registry over the given client.
func New(client redis.UniversalClient, timeout, specTTL time.Duration) *Registry {
	return &Registry{
		client:    client,
		timeout:   timeout,
		specTTL:   specTTL,
		specCache: map[string]cachedSpec{},
	}
}

// Lookup resolves a webhook public hash to its routing entry.
func (r *Registry) Lookup(ctx context.Context, hash string) (Entry, error) {
	ctx, cancel := context.WithTimeout(ctx, r.timeout)
	defer cancel()

	raw, err := r.client.Get(ctx, "webhook:"+hash).Result()

	if errors.Is(err, redis.Nil) {
		return Entry{}, ErrNotFound
	}

	if err != nil {
		return Entry{}, fmt.Errorf("registry: read entry: %w", err)
	}

	var entry Entry
	if err := json.Unmarshal([]byte(raw), &entry); err != nil {
		return Entry{}, fmt.Errorf("registry: malformed entry: %w", err)
	}

	if !entry.valid() {
		return Entry{}, ErrNotFound
	}

	return entry, nil
}

// Spec resolves a platform's ingress spec, caching the outcome briefly.
//
// Absence is cached alongside presence. Platforms whose adapters keep
// verification in PHP have no spec at all, and re-asking Redis for every one of
// their webhooks would add a round trip to a question whose answer rarely changes.
func (r *Registry) Spec(ctx context.Context, platform string) (spec.Spec, error) {
	if cached, ok := r.cachedSpec(platform); ok {
		if !cached.found {
			return spec.Spec{}, ErrNotFound
		}

		return cached.spec, nil
	}

	ctx, cancel := context.WithTimeout(ctx, r.timeout)
	defer cancel()

	raw, err := r.client.Get(ctx, "ingress:spec:"+platform).Result()

	if errors.Is(err, redis.Nil) {
		r.cacheSpec(platform, spec.Spec{}, false)

		return spec.Spec{}, ErrNotFound
	}

	if err != nil {
		// Deliberately not cached: a transient Redis failure must not pin an
		// absence for the whole TTL and force every request through the proxy.
		return spec.Spec{}, fmt.Errorf("registry: read spec: %w", err)
	}

	parsed, err := spec.Parse([]byte(raw))
	if err != nil {
		// A spec this build cannot execute is cached as absent so the gateway
		// stops re-parsing it; the application still verifies these itself.
		r.cacheSpec(platform, spec.Spec{}, false)

		return spec.Spec{}, err
	}

	r.cacheSpec(platform, parsed, true)

	return parsed, nil
}

// MarkProcessed claims an idempotency key, reporting whether this caller won.
//
// Mirrors the controller's Redis SET NX with the same key shape, so a delivery
// retried across both ingress paths still deduplicates.
func (r *Registry) MarkProcessed(ctx context.Context, key string, ttl time.Duration) (bool, error) {
	ctx, cancel := context.WithTimeout(ctx, r.timeout)
	defer cancel()

	won, err := r.client.SetNX(ctx, "processed:"+key, "1", ttl).Result()
	if err != nil {
		return false, fmt.Errorf("registry: claim idempotency key: %w", err)
	}

	return won, nil
}

// ReleaseProcessed gives back a claimed idempotency key.
//
// Called when publishing fails after the claim succeeded. Without this the
// message is lost outright: the provider is told the delivery succeeded, nothing
// reached the queue, and the retry that follows is rejected as a duplicate.
func (r *Registry) ReleaseProcessed(ctx context.Context, key string) error {
	ctx, cancel := context.WithTimeout(ctx, r.timeout)
	defer cancel()

	if err := r.client.Del(ctx, "processed:"+key).Err(); err != nil {
		return fmt.Errorf("registry: release idempotency key: %w", err)
	}

	return nil
}

// Exists reports whether a routing entry is present, without decoding it.
func (r *Registry) Exists(ctx context.Context, hash string) (bool, error) {
	ctx, cancel := context.WithTimeout(ctx, r.timeout)
	defer cancel()

	count, err := r.client.Exists(ctx, "webhook:"+hash).Result()
	if err != nil {
		return false, fmt.Errorf("registry: probe entry: %w", err)
	}

	return count > 0, nil
}

// ForgetSpecs drops the spec cache, so the next request re-reads from Redis.
// Wired to SIGHUP: republished specs should take effect without a restart.
func (r *Registry) ForgetSpecs() {
	r.specMu.Lock()
	defer r.specMu.Unlock()

	r.specCache = map[string]cachedSpec{}
}

func (r *Registry) cachedSpec(platform string) (cachedSpec, bool) {
	r.specMu.RLock()
	defer r.specMu.RUnlock()

	cached, ok := r.specCache[platform]
	if !ok || time.Now().After(cached.expiresAt) {
		return cachedSpec{}, false
	}

	return cached, true
}

func (r *Registry) cacheSpec(platform string, value spec.Spec, found bool) {
	r.specMu.Lock()
	defer r.specMu.Unlock()

	r.specCache[platform] = cachedSpec{spec: value, found: found, expiresAt: time.Now().Add(r.specTTL)}
}
