package config

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func writeDotenv(t *testing.T, contents string) string {
	t.Helper()

	path := filepath.Join(t.TempDir(), ".env")

	if err := os.WriteFile(path, []byte(contents), 0o600); err != nil {
		t.Fatalf("write .env: %v", err)
	}

	return path
}

func TestDotenvParsesTheFormatsLaravelFilesUse(t *testing.T) {
	path := writeDotenv(t, `
# comment line
APP_URL=http://localhost
APP_NAME="FaPost Core"
QUOTED_SINGLE='single value'
WEBHOOK_BASE_URL="${APP_URL}"
export EXPORTED=yes
WITH_COMMENT=value # trailing
EMPTY=
`)

	for _, key := range []string{"APP_URL", "APP_NAME", "QUOTED_SINGLE", "WEBHOOK_BASE_URL", "EXPORTED", "WITH_COMMENT"} {
		t.Setenv(key, "")
		os.Unsetenv(key)
	}

	if err := LoadDotenv(path); err != nil {
		t.Fatalf("load: %v", err)
	}

	expected := map[string]string{
		"APP_URL":          "http://localhost",
		"APP_NAME":         "FaPost Core",
		"QUOTED_SINGLE":    "single value",
		"WEBHOOK_BASE_URL": "http://localhost",
		"EXPORTED":         "yes",
		"WITH_COMMENT":     "value",
	}

	for key, want := range expected {
		if got := os.Getenv(key); got != want {
			t.Errorf("%s = %q, want %q", key, got, want)
		}
	}
}

// A value exported by systemd or the container runtime must win over a file
// sitting beside the code, or deployments could not override anything.
func TestRealEnvironmentWinsOverTheFile(t *testing.T) {
	path := writeDotenv(t, "GATEWAY_ADDR=:9999\n")

	t.Setenv("GATEWAY_ADDR", ":8080")

	if err := LoadDotenv(path); err != nil {
		t.Fatalf("load: %v", err)
	}

	if got := os.Getenv("GATEWAY_ADDR"); got != ":8080" {
		t.Errorf("GATEWAY_ADDR = %q, want the exported value to win", got)
	}
}

// In containers the environment is injected directly and no file exists.
func TestMissingDotenvIsNotAnError(t *testing.T) {
	if err := LoadDotenv(filepath.Join(t.TempDir(), "absent")); err != nil {
		t.Errorf("LoadDotenv(absent) = %v, want nil", err)
	}
}

func TestLoadResolvesUpstreamFromWebhookBaseURL(t *testing.T) {
	t.Setenv("WEBHOOK_BASE_URL", "https://app.example.com")
	t.Setenv("GATEWAY_UPSTREAM_URL", "")

	settings, err := Load("")
	if err != nil {
		t.Fatalf("load: %v", err)
	}

	if settings.Upstream.String() != "https://app.example.com" {
		t.Errorf("upstream = %q, want the application ingress", settings.Upstream)
	}
}

// Without an upstream the gateway has nowhere to fall back to on a cache miss,
// which is the difference between degrading and dropping deliveries. Better to
// refuse to start than to discover that during an outage.
func TestLoadRequiresAnUpstream(t *testing.T) {
	t.Setenv("WEBHOOK_BASE_URL", "")
	t.Setenv("GATEWAY_UPSTREAM_URL", "")

	if _, err := Load(""); err == nil {
		t.Fatal("expected an error when no upstream is configured")
	}
}

func TestLoadRejectsAnUpstreamWithoutSchemeOrHost(t *testing.T) {
	t.Setenv("GATEWAY_UPSTREAM_URL", "app.example.com")

	if _, err := Load(""); err == nil {
		t.Fatal("expected an error for an upstream without a scheme")
	}
}

// A mismatched prefix is invisible rather than noisy: every lookup simply misses
// and the gateway proxies everything while looking healthy, so the fallback
// reproduces the framework's own derivation instead of assuming no prefix.
func TestRedisPrefixFallsBackToTheFrameworkDerivation(t *testing.T) {
	t.Setenv("GATEWAY_UPSTREAM_URL", "https://app.example.com")
	os.Unsetenv("REDIS_PREFIX")
	t.Setenv("APP_NAME", "FaPost Core")

	settings, err := Load("")
	if err != nil {
		t.Fatalf("load: %v", err)
	}

	if settings.Redis.Prefix != "fapost-core-database-" {
		t.Errorf("prefix = %q, want fapost-core-database-", settings.Redis.Prefix)
	}
}

func TestExplicitRedisPrefixWinsIncludingAnEmptyOne(t *testing.T) {
	t.Setenv("GATEWAY_UPSTREAM_URL", "https://app.example.com")
	t.Setenv("REDIS_PREFIX", "")

	settings, err := Load("")
	if err != nil {
		t.Fatalf("load: %v", err)
	}

	if settings.Redis.Prefix != "" {
		t.Errorf("prefix = %q, want empty when REDIS_PREFIX is set to an empty value", settings.Redis.Prefix)
	}
}

func TestDurationsAcceptBareSeconds(t *testing.T) {
	t.Setenv("GATEWAY_UPSTREAM_URL", "https://app.example.com")
	t.Setenv("GATEWAY_READ_TIMEOUT", "30")
	t.Setenv("GATEWAY_DEDUP_TTL", "1h")

	settings, err := Load("")
	if err != nil {
		t.Fatalf("load: %v", err)
	}

	if settings.HTTP.ReadTimeout != 30*time.Second {
		t.Errorf("read timeout = %v, want 30s", settings.HTTP.ReadTimeout)
	}

	if settings.DedupTTL != time.Hour {
		t.Errorf("dedup TTL = %v, want 1h", settings.DedupTTL)
	}
}

func TestSlugMatchesTheFrameworkForApplicationNames(t *testing.T) {
	cases := map[string]string{
		"FaPost Core":  "fapost-core",
		"fapost_core":  "fapost-core",
		"  FaPost  ":   "fapost",
		"FaPost--Core": "fapost-core",
	}

	for input, want := range cases {
		if got := slug(input); got != want {
			t.Errorf("slug(%q) = %q, want %q", input, got, want)
		}
	}
}
