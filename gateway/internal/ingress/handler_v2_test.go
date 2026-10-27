package ingress

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"fapost/gateway/internal/spec"
)

const (
	slackSecret    = "8f742231b10e8888abcd99yyyzzz85a5"
	slackTimestamp = int64(1531420618)
)

func slackSpec(t *testing.T) spec.Spec {
	t.Helper()

	parsed, err := spec.Parse([]byte(`{"v":2,"scheme":"hmac_sha256","parameter":"x-slack-signature","prefix":"v0=",
		"idempotency":"slack:{channel}:{body.event_id|body.trigger_id}",
		"signed":"v0:{header.x-slack-request-timestamp}:{raw}",
		"timestamp":{"header":"x-slack-request-timestamp","tolerance":300},"form":"payload",
		"handshake":{"when":"{body.type}","equals":"url_verification","respond":"{body.challenge}"}}`))
	if err != nil {
		t.Fatalf("parse slack spec: %v", err)
	}

	return parsed
}

func slackHarness(t *testing.T, now time.Time, logs *bytes.Buffer) *harness {
	t.Helper()

	h := newHarness(t, func(c *fakeChannels) {
		c.entry.Platform = "slack"
		c.entry.SecretToken = slackSecret
		c.spec = slackSpec(t)
	})

	h.handler.options.Now = func() time.Time { return now }

	if logs != nil {
		h.handler.options.Logger = slog.New(slog.NewTextHandler(logs, nil))
	}

	return h
}

func slackSignature(secret, timestamp, body string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte("v0:" + timestamp + ":" + body))

	return "v0=" + hex.EncodeToString(mac.Sum(nil))
}

func deliverSlack(h *Handler, body, timestamp, signature string) *httptest.ResponseRecorder {
	request := httptest.NewRequest(http.MethodPost, "/webhook/slack/"+testHash, strings.NewReader(body))
	request.Header.Set("X-Slack-Request-Timestamp", timestamp)
	request.Header.Set("X-Slack-Signature", signature)

	recorder := httptest.NewRecorder()
	h.ServeHTTP(recorder, request)

	return recorder
}

func signedSlack(h *Handler, body string) *httptest.ResponseRecorder {
	timestamp := "1531420618"

	return deliverSlack(h, body, timestamp, slackSignature(slackSecret, timestamp, body))
}

func TestHandshakeIsAnsweredAsPlainTextWithoutQueueingOrDedup(t *testing.T) {
	h := slackHarness(t, time.Unix(slackTimestamp, 0), nil)
	h.channels.claimErr = errRedisDown // a dedup claim would send the request to the application

	response := signedSlack(h.handler, `{"token":"x","challenge":"3eZbrw1aBm2rZgRNFdxV","type":"url_verification"}`)

	if response.Code != http.StatusOK {
		t.Fatalf("status = %d, want 200", response.Code)
	}

	if got := response.Body.String(); got != "3eZbrw1aBm2rZgRNFdxV" {
		t.Errorf("body = %q, want the challenge", got)
	}

	if got := response.Header().Get("Content-Type"); got != "text/plain; charset=utf-8" {
		t.Errorf("Content-Type = %q", got)
	}

	if len(h.publisher.pushed()) != 0 {
		t.Error("a handshake must not be queued")
	}

	if len(h.upstreamCalls()) != 0 {
		t.Error("a handshake must not reach the application")
	}
}

func TestHandshakeWithABadSignatureIsNotAnswered(t *testing.T) {
	h := slackHarness(t, time.Unix(slackTimestamp, 0), nil)

	body := `{"challenge":"secret-answer","type":"url_verification"}`
	response := deliverSlack(h.handler, body, "1531420618", "v0="+strings.Repeat("0", 64))

	if response.Body.String() != `{"ok":true}` {
		t.Errorf("body = %q, want the opaque ok", response.Body.String())
	}

	if strings.Contains(response.Body.String(), "secret-answer") {
		t.Error("an unverified request must never learn the challenge answer")
	}
}

func TestFormDeliveryIsQueuedWithTheDecodedPayloadAndKey(t *testing.T) {
	h := slackHarness(t, time.Unix(slackTimestamp, 0), nil)

	body := "payload=%7B%22type%22%3A%22block_actions%22%2C%22trigger_id%22%3A%2213345.738%22%2C%22user%22%3A%7B%22id%22%3A%22U1%22%7D%7D"
	response := signedSlack(h.handler, body)

	if response.Body.String() != `{"ok":true}` {
		t.Fatalf("body = %q", response.Body.String())
	}

	pushed := h.publisher.pushed()
	if len(pushed) != 1 {
		t.Fatalf("pushed %d jobs, want 1", len(pushed))
	}

	if got := pushed[0]["idempotencyKey"]; got != "slack:channel-777:13345.738" {
		t.Errorf("idempotencyKey = %v", got)
	}

	raw, ok := pushed[0]["rawPayload"].(map[string]any)
	if !ok {
		t.Fatalf("rawPayload = %v, want an object", pushed[0]["rawPayload"])
	}

	if raw["type"] != "block_actions" {
		t.Errorf("rawPayload.type = %v", raw["type"])
	}

	user, _ := raw["user"].(map[string]any)
	if user["id"] != "U1" {
		t.Errorf("rawPayload.user = %v", raw["user"])
	}
}

func TestEventCallbackIsQueuedUnderItsEventID(t *testing.T) {
	h := slackHarness(t, time.Unix(slackTimestamp, 0), nil)

	signedSlack(h.handler, `{"type":"event_callback","event_id":"Ev0PV52K21","event":{"type":"message"}}`)

	pushed := h.publisher.pushed()
	if len(pushed) != 1 {
		t.Fatalf("pushed %d jobs, want 1", len(pushed))
	}

	if got := pushed[0]["idempotencyKey"]; got != "slack:channel-777:Ev0PV52K21" {
		t.Errorf("idempotencyKey = %v", got)
	}
}

func TestStaleTimestampIsRefusedAndLoggedSeparately(t *testing.T) {
	var logs bytes.Buffer

	h := slackHarness(t, time.Unix(slackTimestamp+301, 0), &logs)

	response := signedSlack(h.handler, `{"type":"event_callback","event_id":"Ev1"}`)

	if response.Body.String() != `{"ok":true}` {
		t.Errorf("body = %q, want the opaque ok", response.Body.String())
	}

	if len(h.publisher.pushed()) != 0 {
		t.Error("a stale delivery must not be queued")
	}

	if !strings.Contains(logs.String(), "stale_timestamp") {
		t.Errorf("log does not name the stale timestamp: %s", logs.String())
	}

	if strings.Contains(logs.String(), "bad_signature") {
		t.Errorf("a stale timestamp must not be logged as a bad signature: %s", logs.String())
	}
}

func TestBadSignatureWithAFreshTimestampStaysBadSignature(t *testing.T) {
	var logs bytes.Buffer

	h := slackHarness(t, time.Unix(slackTimestamp, 0), &logs)

	deliverSlack(h.handler, `{"type":"event_callback"}`, "1531420618", "v0="+strings.Repeat("0", 64))

	if !strings.Contains(logs.String(), "bad_signature") {
		t.Errorf("log does not name the bad signature: %s", logs.String())
	}
}

func TestToleranceBoundaryUsesTheInjectedClock(t *testing.T) {
	for offset, want := range map[int64]int{300: 1, -300: 1, 301: 0, -301: 0} {
		h := slackHarness(t, time.Unix(slackTimestamp+offset, 0), nil)

		signedSlack(h.handler, `{"type":"event_callback","event_id":"Ev1"}`)

		if got := len(h.publisher.pushed()); got != want {
			t.Errorf("offset %d: pushed %d jobs, want %d", offset, got, want)
		}
	}
}

func TestHandlerDefaultsTheClockToTimeNow(t *testing.T) {
	handler := New(Options{Logger: slog.New(slog.NewTextHandler(io.Discard, nil))})

	if handler.options.Now == nil {
		t.Fatal("Now must default to time.Now")
	}
}
