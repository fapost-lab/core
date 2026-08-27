// Command gateway is the stateless webhook ingress in front of the application.
//
// It accepts provider deliveries, verifies them against specs the application
// publishes, and queues them for the existing worker. Everything it cannot
// decide locally is proxied to the application's own ingress, so Redis being
// cold or unreachable costs throughput rather than availability.
package main

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/redis/go-redis/v9"

	"fapost/gateway/internal/config"
	"fapost/gateway/internal/ingress"
	"fapost/gateway/internal/logging"
	"fapost/gateway/internal/queue"
	"fapost/gateway/internal/ratelimit"
	"fapost/gateway/internal/registry"
)

// bucketIdle is how long an unused rate-limit bucket is kept before sweeping.
const bucketIdle = 10 * time.Minute

// version is stamped at build time via -ldflags. Without it a running gateway
// cannot be traced back to a commit, which is the first question asked when
// something misbehaves on the edge.
var version = "dev"

func main() {
	dotenv := flag.String("env", ".env", "path to the shared .env file; empty to use the process environment only")
	showVersion := flag.Bool("version", false, "print the build version and exit")
	flag.Parse()

	if *showVersion {
		fmt.Println(version)

		return
	}

	if err := run(*dotenv); err != nil {
		fmt.Fprintf(os.Stderr, "gateway: %v\n", err)
		os.Exit(1)
	}
}

func run(dotenvPath string) error {
	settings, err := config.Load(dotenvPath)
	if err != nil {
		return err
	}

	logger, err := logging.New(logging.Options{
		Level:       logging.ParseLevel(settings.Log.Level),
		Format:      logging.ParseFormat(settings.Log.Format),
		Destination: logging.ParseDestination(settings.Log.Destination),
		Path:        settings.Log.Path,
	})
	if err != nil {
		return err
	}
	defer logger.Close()

	client := redis.NewClient(&redis.Options{
		Addr:     settings.Redis.Addr,
		Username: settings.Redis.Username,
		Password: settings.Redis.Password,
		DB:       settings.Redis.DB,
	})
	defer client.Close()

	// The key prefix has to match the application's, or every lookup misses and
	// the gateway proxies everything while looking perfectly healthy.
	client.AddHook(prefixHook{prefix: settings.Redis.Prefix})

	channels := registry.New(client, settings.Redis.Timeout, settings.SpecCacheTTL)
	limiter := ratelimit.New(settings.RatePerSecond, settings.RateBurst)

	handler := ingress.New(ingress.Options{
		Registry:       channels,
		Publisher:      queue.New(client, settings.Queue.Name, settings.Redis.Timeout),
		Limiter:        limiter,
		Logger:         logger.Logger,
		Upstream:       settings.Upstream,
		MaxBodyBytes:   settings.MaxBodyBytes,
		DedupTTL:       settings.DedupTTL,
		TrustedProxies: settings.TrustedProxies,
	})

	server := &http.Server{
		Addr:    settings.Addr,
		Handler: withHealth(handler),

		// ReadHeaderTimeout is the one that matters on a public edge: without it a
		// client can hold a connection open indefinitely by dribbling headers.
		ReadHeaderTimeout: settings.HTTP.ReadTimeout,
		ReadTimeout:       settings.HTTP.ReadTimeout,
		WriteTimeout:      settings.HTTP.WriteTimeout,
		IdleTimeout:       settings.HTTP.IdleTimeout,
	}

	logger.Info("gateway starting",
		slog.String("version", version),
		slog.String("addr", settings.Addr),
		slog.String("upstream", settings.Upstream.String()),
		slog.String("queue", settings.Queue.Name),
		slog.String("redis", settings.Redis.Addr),
		slog.Bool("external_log_rotation", logger.RotatesExternally()))

	return serve(server, logger, channels, limiter, settings.HTTP.ShutdownTimeout)
}

func serve(
	server *http.Server,
	logger *logging.Logger,
	channels *registry.Registry,
	limiter *ratelimit.Limiter,
	shutdownTimeout time.Duration,
) error {
	failed := make(chan error, 1)

	go func() {
		if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			failed <- err
		}
	}()

	reload := make(chan os.Signal, 1)
	signal.Notify(reload, syscall.SIGHUP)
	defer signal.Stop(reload)

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	defer signal.Stop(stop)

	sweep := time.NewTicker(bucketIdle)
	defer sweep.Stop()

	for {
		select {
		case err := <-failed:
			return fmt.Errorf("listen on %s: %w", server.Addr, err)

		case <-reload:
			// One signal, both jobs: reopen the log file so logrotate can rotate
			// underneath us, and drop cached specs so a republish takes effect
			// without a restart.
			if err := logger.Reopen(); err != nil {
				logger.Error("could not reopen log file", slog.String("error", err.Error()))
			}

			channels.ForgetSpecs()
			logger.Info("reloaded on SIGHUP")

		case <-sweep.C:
			if removed := limiter.Sweep(bucketIdle); removed > 0 {
				logger.Debug("swept idle rate limit buckets", slog.Int("removed", removed))
			}

		case signalReceived := <-stop:
			logger.Info("shutting down", slog.String("signal", signalReceived.String()))

			// Draining matters more here than in most services: a delivery already
			// accepted has been answered with 200, and the provider will not send
			// it again. Cutting those requests off loses messages outright.
			ctx, cancel := context.WithTimeout(context.Background(), shutdownTimeout)
			defer cancel()

			if err := server.Shutdown(ctx); err != nil {
				return fmt.Errorf("graceful shutdown: %w", err)
			}

			logger.Info("stopped")

			return nil
		}
	}
}

// withHealth answers readiness probes without involving the ingress handler.
//
// Kept dependency-free on purpose: it reports that this process is up and able to
// serve, not that Redis is reachable. Reporting unhealthy on a Redis outage would
// pull the gateway out of the load balancer exactly when its proxy fallback is
// the thing keeping deliveries flowing.
func withHealth(handler http.Handler) http.Handler {
	mux := http.NewServeMux()

	mux.HandleFunc("/healthz", func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"status":"ok"}`))
	})

	mux.Handle("/", handler)

	return mux
}

// prefixHook applies the application's Redis key prefix to every command.
type prefixHook struct {
	prefix string
}

func (h prefixHook) DialHook(next redis.DialHook) redis.DialHook {
	return next
}

func (h prefixHook) ProcessHook(next redis.ProcessHook) redis.ProcessHook {
	return func(ctx context.Context, cmd redis.Cmder) error {
		h.applyPrefix(cmd)

		return next(ctx, cmd)
	}
}

func (h prefixHook) ProcessPipelineHook(next redis.ProcessPipelineHook) redis.ProcessPipelineHook {
	return func(ctx context.Context, cmds []redis.Cmder) error {
		for _, cmd := range cmds {
			h.applyPrefix(cmd)
		}

		return next(ctx, cmds)
	}
}

// applyPrefix rewrites the key argument, which Redis commands carry in position 1.
func (h prefixHook) applyPrefix(cmd redis.Cmder) {
	if h.prefix == "" {
		return
	}

	args := cmd.Args()
	if len(args) < 2 {
		return
	}

	if key, ok := args[1].(string); ok {
		args[1] = h.prefix + key
	}
}
