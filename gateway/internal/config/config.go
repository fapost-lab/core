// Package config assembles the gateway's settings from the environment.
//
// Bootstrap values only: how to reach Redis, where to listen, where to log, and
// where to proxy. Everything the gateway needs about channels — routing entries
// and ingress specs — is read from Redis at request time, published there by the
// application, so those never appear here.
package config

import (
	"fmt"
	"net/netip"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"
)

// Defaults chosen to be safe rather than fast: a gateway that starts with the
// wrong settings should still behave correctly, just less efficiently.
const (
	defaultAddr            = ":8080"
	defaultQueue           = "flow.execution"
	defaultMaxBodyBytes    = 1 << 20 // 1 MiB — well above any provider's update payload.
	defaultRatePerSecond   = 30
	defaultRateBurst       = 60
	defaultDedupTTL        = 24 * time.Hour
	defaultReadTimeout     = 10 * time.Second
	defaultWriteTimeout    = 15 * time.Second
	defaultIdleTimeout     = 60 * time.Second
	defaultShutdownTimeout = 20 * time.Second
	defaultRedisTimeout    = 2 * time.Second
	defaultSpecCacheTTL    = 30 * time.Second
)

// Config is the resolved gateway configuration.
type Config struct {
	Addr     string
	Upstream *url.URL

	// UpstreamPreserveHost sends the fallback request with the Host the caller used rather than the
	// upstream URL's own. Off by default: an upstream that is the public application URL behind the same
	// proxy that fronts the gateway would route a preserved gateway Host straight back here. On for an
	// internal upstream (http://web:80) that serves every host, which host tenancy mode needs.
	UpstreamPreserveHost bool

	Redis Redis
	Queue Queue
	Log   Log
	HTTP  HTTP

	MaxBodyBytes  int64
	RatePerSecond float64
	RateBurst     int
	DedupTTL      time.Duration
	SpecCacheTTL  time.Duration

	// TrustedProxies lists the networks whose X-Forwarded-For may be believed.
	// Empty means the header is ignored entirely — trusting it unconditionally
	// would let any caller forge a client address and slip past rate limiting.
	//
	// Entries are ranges rather than plain addresses because a sidecar proxy
	// rarely has a stable one: on a compose network the address of whatever
	// terminates TLS is handed out by Docker and changes whenever the container
	// is recreated, so the subnet is the only value an operator can pin down.
	TrustedProxies []netip.Prefix
}

// Redis describes how to reach the shared instance.
type Redis struct {
	Addr     string
	Username string
	Password string
	DB       int

	// Prefix must match the application's database.redis.options.prefix, or the
	// gateway would read and write a disjoint set of keys and silently see an
	// empty registry.
	Prefix string

	Timeout time.Duration
}

// Queue describes where accepted webhooks are published.
type Queue struct {
	Name string
}

// Log describes logging destination and verbosity.
type Log struct {
	Level       string
	Format      string
	Destination string
	Path        string
}

// HTTP holds server timeouts.
type HTTP struct {
	ReadTimeout     time.Duration
	WriteTimeout    time.Duration
	IdleTimeout     time.Duration
	ShutdownTimeout time.Duration
}

// Load resolves configuration from the environment, after optionally seeding it
// from a shared .env file.
func Load(dotenvPath string) (Config, error) {
	if dotenvPath != "" {
		if err := LoadDotenv(dotenvPath); err != nil {
			return Config{}, fmt.Errorf("config: read %s: %w", dotenvPath, err)
		}
	}

	upstream, err := resolveUpstream()
	if err != nil {
		return Config{}, err
	}

	trustedProxies, err := parseTrustedProxies(envList("GATEWAY_TRUSTED_PROXIES"))
	if err != nil {
		return Config{}, err
	}

	return Config{
		Addr:     env("GATEWAY_ADDR", defaultAddr),
		Upstream: upstream,

		UpstreamPreserveHost: envBool("GATEWAY_UPSTREAM_PRESERVE_HOST", false),

		Redis: Redis{
			Addr:     fmt.Sprintf("%s:%s", env("REDIS_HOST", "127.0.0.1"), env("REDIS_PORT", "6379")),
			Username: env("REDIS_USERNAME", ""),
			Password: env("REDIS_PASSWORD", ""),
			DB:       envInt("REDIS_DB", 0),
			Prefix:   redisPrefix(),
			Timeout:  envDuration("GATEWAY_REDIS_TIMEOUT", defaultRedisTimeout),
		},
		Queue: Queue{Name: env("GATEWAY_QUEUE", defaultQueue)},
		Log: Log{
			Level:       env("GATEWAY_LOG_LEVEL", "info"),
			Format:      env("GATEWAY_LOG_FORMAT", "json"),
			Destination: env("GATEWAY_LOG_DESTINATION", "stdout"),
			Path:        env("GATEWAY_LOG_PATH", ""),
		},
		HTTP: HTTP{
			ReadTimeout:     envDuration("GATEWAY_READ_TIMEOUT", defaultReadTimeout),
			WriteTimeout:    envDuration("GATEWAY_WRITE_TIMEOUT", defaultWriteTimeout),
			IdleTimeout:     envDuration("GATEWAY_IDLE_TIMEOUT", defaultIdleTimeout),
			ShutdownTimeout: envDuration("GATEWAY_SHUTDOWN_TIMEOUT", defaultShutdownTimeout),
		},
		MaxBodyBytes:   int64(envInt("GATEWAY_MAX_BODY_BYTES", defaultMaxBodyBytes)),
		RatePerSecond:  envFloat("GATEWAY_RATE_PER_SECOND", defaultRatePerSecond),
		RateBurst:      envInt("GATEWAY_RATE_BURST", defaultRateBurst),
		DedupTTL:       envDuration("GATEWAY_DEDUP_TTL", defaultDedupTTL),
		SpecCacheTTL:   envDuration("GATEWAY_SPEC_CACHE_TTL", defaultSpecCacheTTL),
		TrustedProxies: trustedProxies,
	}, nil
}

// parseTrustedProxies resolves the configured entries into networks.
//
// Both forms are accepted: "10.0.0.5" trusts exactly that peer, "172.16.0.0/12"
// trusts a whole range — which is what makes the setting usable behind a proxy
// whose address the container runtime assigns.
//
// A malformed entry fails the load rather than being dropped silently. Skipping
// it would leave the gateway trusting nothing while looking configured, and the
// symptom — every delivery rate-limited under the proxy's own address, because
// X-Forwarded-For is never believed — points nowhere near the typo that caused it.
func parseTrustedProxies(entries []string) ([]netip.Prefix, error) {
	prefixes := make([]netip.Prefix, 0, len(entries))

	for _, entry := range entries {
		if strings.Contains(entry, "/") {
			prefix, err := netip.ParsePrefix(entry)
			if err != nil {
				return nil, fmt.Errorf("config: GATEWAY_TRUSTED_PROXIES entry %q is not a valid CIDR range: %w", entry, err)
			}

			prefixes = append(prefixes, prefix.Masked())

			continue
		}

		address, err := netip.ParseAddr(entry)
		if err != nil {
			return nil, fmt.Errorf("config: GATEWAY_TRUSTED_PROXIES entry %q is not a valid address or CIDR range: %w", entry, err)
		}

		// A single address is the range that contains only itself, so matching
		// has one shape to deal with rather than two.
		address = address.Unmap()
		prefixes = append(prefixes, netip.PrefixFrom(address, address.BitLen()))
	}

	return prefixes, nil
}

// resolveUpstream determines where unhandled requests are proxied.
//
// This is the application's own ingress, and it is mandatory: without it the
// gateway has nowhere to fall back to on a cache miss and would have to reject
// requests the application could have served.
func resolveUpstream() (*url.URL, error) {
	raw := env("GATEWAY_UPSTREAM_URL", env("WEBHOOK_BASE_URL", ""))

	if raw == "" {
		return nil, fmt.Errorf("config: GATEWAY_UPSTREAM_URL (or WEBHOOK_BASE_URL) is required for proxy fallback")
	}

	parsed, err := url.Parse(raw)
	if err != nil {
		return nil, fmt.Errorf("config: upstream URL %q is not valid: %w", raw, err)
	}

	if parsed.Scheme == "" || parsed.Host == "" {
		return nil, fmt.Errorf("config: upstream URL %q must include a scheme and host", raw)
	}

	return parsed, nil
}

// redisPrefix mirrors the application's default when REDIS_PREFIX is unset:
// slug(APP_NAME) + "-database-". Getting this wrong is invisible rather than
// noisy — every lookup simply misses — so the fallback reproduces the framework's
// own derivation instead of assuming no prefix.
func redisPrefix() string {
	if prefix, ok := os.LookupEnv("REDIS_PREFIX"); ok {
		return prefix
	}

	return slug(env("APP_NAME", "laravel")) + "-database-"
}

// slug reproduces Str::slug for the ASCII inputs an application name uses.
func slug(value string) string {
	var builder strings.Builder

	previousDash := false

	for _, r := range strings.ToLower(strings.TrimSpace(value)) {
		switch {
		case (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9'):
			builder.WriteRune(r)
			previousDash = false
		case r == ' ' || r == '-' || r == '_':
			if !previousDash && builder.Len() > 0 {
				builder.WriteRune('-')
				previousDash = true
			}
		}
	}

	return strings.Trim(builder.String(), "-")
}

func env(key, fallback string) string {
	if value, ok := os.LookupEnv(key); ok && value != "" {
		return value
	}

	return fallback
}

func envBool(key string, fallback bool) bool {
	if value, err := strconv.ParseBool(env(key, "")); err == nil {
		return value
	}

	return fallback
}

func envInt(key string, fallback int) int {
	if value, err := strconv.Atoi(env(key, "")); err == nil {
		return value
	}

	return fallback
}

func envFloat(key string, fallback float64) float64 {
	if value, err := strconv.ParseFloat(env(key, ""), 64); err == nil {
		return value
	}

	return fallback
}

func envDuration(key string, fallback time.Duration) time.Duration {
	raw := env(key, "")

	if raw == "" {
		return fallback
	}

	if value, err := time.ParseDuration(raw); err == nil {
		return value
	}

	// A bare number is read as seconds, which is how these knobs are usually written.
	if seconds, err := strconv.Atoi(raw); err == nil {
		return time.Duration(seconds) * time.Second
	}

	return fallback
}

func envList(key string) []string {
	raw := env(key, "")

	if raw == "" {
		return nil
	}

	parts := strings.Split(raw, ",")
	list := make([]string, 0, len(parts))

	for _, part := range parts {
		if trimmed := strings.TrimSpace(part); trimmed != "" {
			list = append(list, trimmed)
		}
	}

	return list
}
