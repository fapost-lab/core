package queue

import (
	"encoding/json"
	"testing"
)

// The envelope is read by Laravel's queue worker, so its field names and values
// are a contract with another language. A rename here does not fail any build —
// it makes jobs stop running in production.
func TestPayloadMatchesTheFrameworkEnvelope(t *testing.T) {
	body, err := BuildPayload("req-1", map[string]any{"v": 1, "tenantId": "t-1"})
	if err != nil {
		t.Fatalf("build payload: %v", err)
	}

	var envelope map[string]any
	if err := json.Unmarshal(body, &envelope); err != nil {
		t.Fatalf("payload is not JSON: %v", err)
	}

	expected := map[string]any{
		"uuid":          "req-1",
		"id":            "req-1",
		"displayName":   `App\Domains\Webhook\Jobs\IncomingMessageJob`,
		"job":           `App\Domains\Webhook\Jobs\RawIncomingMessageHandler@handle`,
		"maxTries":      float64(maxTries),
		"attempts":      float64(0),
		"failOnTimeout": false,
	}

	for key, want := range expected {
		if got := envelope[key]; got != want {
			t.Errorf("%s = %v, want %v", key, got, want)
		}
	}

	for _, key := range []string{"maxExceptions", "backoff", "timeout"} {
		value, present := envelope[key]
		if !present {
			t.Errorf("%s is missing; the worker reads it off the payload", key)
		}

		if value != nil {
			t.Errorf("%s = %v, want null", key, value)
		}
	}
}

// The job runs through a bridge, but Horizon should show the job that actually
// does the work — otherwise gateway traffic looks like a separate workload.
func TestDisplayNameNamesTheRealJobNotTheBridge(t *testing.T) {
	if displayName == handlerMethod {
		t.Fatal("displayName must name the job, not the bridge handler")
	}
}

func TestPayloadCarriesDataVerbatim(t *testing.T) {
	// Values are written as they survive a JSON round trip, so the assertions
	// below compare like with like rather than int against float64.
	data := map[string]any{
		"v":              float64(1),
		"tenantId":       "tenant-1",
		"rawPayload":     map[string]any{"update_id": float64(99)},
		"idempotencyKey": "tg:channel-1:99",
		"requestId":      "req-1",
	}

	body, err := BuildPayload("req-1", data)
	if err != nil {
		t.Fatalf("build payload: %v", err)
	}

	var envelope struct {
		Data map[string]any `json:"data"`
	}

	if err := json.Unmarshal(body, &envelope); err != nil {
		t.Fatalf("payload is not JSON: %v", err)
	}

	for key, want := range data {
		if key == "rawPayload" {
			continue
		}

		if got := envelope.Data[key]; got != want {
			t.Errorf("data.%s = %v, want %v", key, got, want)
		}
	}

	raw, ok := envelope.Data["rawPayload"].(map[string]any)
	if !ok {
		t.Fatalf("data.rawPayload = %v, want an object", envelope.Data["rawPayload"])
	}

	if raw["update_id"] != float64(99) {
		t.Errorf("data.rawPayload.update_id = %v, want 99", raw["update_id"])
	}
}
