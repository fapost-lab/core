// Package queue publishes accepted webhooks onto the application's Laravel queue.
//
// Laravel normally serializes a job as a PHP object graph, which no external
// process can produce without depending on framework internals. Its older
// "Class@method" payload form carries plain JSON instead, and that is what this
// package writes — decoded on the other side by RawIncomingMessageHandler, which
// hands it to the very same job the PHP controller dispatches.
package queue

import (
	"context"
	"encoding/json"
	"fmt"
	"time"

	"github.com/redis/go-redis/v9"
)

const (
	// handlerMethod is the PHP entry point for JSON-carrying jobs.
	handlerMethod = `App\Domains\Webhook\Jobs\RawIncomingMessageHandler@handle`

	// displayName is what Horizon shows. It names the job that actually runs,
	// not the bridge, so gateway-queued work is indistinguishable from the
	// controller's in dashboards and metrics.
	displayName = `App\Domains\Webhook\Jobs\IncomingMessageJob`

	// maxTries mirrors IncomingMessageJob::$tries. The property is read off the
	// deserialized object, which a string payload has none of, so the value has
	// to be stated here — and kept in step with the PHP side.
	maxTries = 5
)

// BuildPayload encodes the queue envelope for a job.
//
// Separate from the push so the wire format can be asserted without a Redis to
// push into. The shape is what the framework's worker reads, and a drift here is
// invisible until a job silently fails to run in production.
func BuildPayload(id string, data any) ([]byte, error) {
	body, err := json.Marshal(payload{
		UUID:        id,
		DisplayName: displayName,
		Job:         handlerMethod,
		MaxTries:    maxTries,
		Data:        data,
		ID:          id,
		Attempts:    0,
	})
	if err != nil {
		return nil, fmt.Errorf("queue: encode payload: %w", err)
	}

	return body, nil
}

// Publisher writes jobs onto a Redis-backed Laravel queue.
type Publisher struct {
	client  redis.UniversalClient
	queue   string
	timeout time.Duration
}

// New builds a publisher for the given queue name.
func New(client redis.UniversalClient, queue string, timeout time.Duration) *Publisher {
	return &Publisher{client: client, queue: queue, timeout: timeout}
}

// payload is the envelope Laravel's queue worker reads.
type payload struct {
	UUID          string `json:"uuid"`
	DisplayName   string `json:"displayName"`
	Job           string `json:"job"`
	MaxTries      int    `json:"maxTries"`
	MaxExceptions *int   `json:"maxExceptions"`
	FailOnTimeout bool   `json:"failOnTimeout"`
	Backoff       *int   `json:"backoff"`
	Timeout       *int   `json:"timeout"`
	Data          any    `json:"data"`
	ID            string `json:"id"`
	Attempts      int    `json:"attempts"`
}

// Push enqueues one job carrying the given data.
//
// The identifier is supplied rather than generated so it can be the same
// correlation id the gateway logged and returned to the caller, which is what
// makes a single webhook traceable across both processes.
func (p *Publisher) Push(ctx context.Context, id string, data any) error {
	body, err := BuildPayload(id, data)
	if err != nil {
		return err
	}

	ctx, cancel := context.WithTimeout(ctx, p.timeout)
	defer cancel()

	key := "queues:" + p.queue

	// Both pushes in one round trip, matching Laravel's own push script. The
	// notify list is what wakes a worker blocked on BLPOP; without it the job
	// waits for the next poll instead of being picked up immediately.
	_, err = p.client.TxPipelined(ctx, func(pipe redis.Pipeliner) error {
		pipe.RPush(ctx, key, body)
		pipe.RPush(ctx, key+":notify", 1)

		return nil
	})
	if err != nil {
		return fmt.Errorf("queue: push to %s: %w", key, err)
	}

	return nil
}
