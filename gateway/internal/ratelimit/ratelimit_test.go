package ratelimit

import (
	"sync"
	"testing"
	"testing/synctest"
	"time"
)

// Time-dependent behaviour is exercised inside a synctest bubble, where the time
// package runs on a fake clock. The waits below are instant, and the limiter
// itself needs no injected clock — the seam that used to exist purely for tests
// is gone from the production type.

func TestBurstIsAdmittedThenBlocked(t *testing.T) {
	limiter := New(1, 3)

	for i := range 3 {
		if !limiter.Allow("channel") {
			t.Fatalf("request %d was blocked inside the burst allowance", i+1)
		}
	}

	if limiter.Allow("channel") {
		t.Error("a request past the burst allowance was admitted")
	}
}

func TestTokensRefillOverTime(t *testing.T) {
	synctest.Test(t, func(t *testing.T) {
		limiter := New(10, 1)

		if !limiter.Allow("channel") {
			t.Fatal("first request was blocked")
		}

		if limiter.Allow("channel") {
			t.Fatal("second immediate request was admitted")
		}

		// At ten per second one token is back after 100ms; the bubble makes this
		// wait instant instead of actually sleeping.
		time.Sleep(200 * time.Millisecond)

		if !limiter.Allow("channel") {
			t.Error("a request after the refill interval was still blocked")
		}
	})
}

// Refill must be proportional to elapsed time, not a flat reset — otherwise a
// caller could park for a millisecond and get the whole burst back.
func TestRefillIsProportionalToElapsedTime(t *testing.T) {
	synctest.Test(t, func(t *testing.T) {
		limiter := New(10, 10)

		for range 10 {
			limiter.Allow("channel")
		}

		time.Sleep(300 * time.Millisecond)

		admitted := 0
		for range 10 {
			if limiter.Allow("channel") {
				admitted++
			}
		}

		if admitted != 3 {
			t.Errorf("admitted %d after 300ms at 10/s, want 3", admitted)
		}
	})
}

// Tokens accumulate only up to the burst, so an idle channel cannot bank
// capacity and then flood.
func TestRefillIsCappedAtBurst(t *testing.T) {
	synctest.Test(t, func(t *testing.T) {
		limiter := New(10, 2)

		limiter.Allow("channel")
		limiter.Allow("channel")

		time.Sleep(time.Hour)

		admitted := 0
		for range 10 {
			if limiter.Allow("channel") {
				admitted++
			}
		}

		if admitted != 2 {
			t.Errorf("admitted %d after an hour idle, want the burst of 2", admitted)
		}
	})
}

// One noisy channel must not consume another channel's allowance.
func TestKeysAreLimitedIndependently(t *testing.T) {
	limiter := New(1, 1)

	if !limiter.Allow("first") {
		t.Fatal("first channel was blocked")
	}

	if !limiter.Allow("second") {
		t.Error("second channel was blocked by the first channel's usage")
	}
}

// The rate limit is an operational safeguard, so it must be possible to turn off
// without removing it from the request path.
func TestNonPositiveRateDisablesLimiting(t *testing.T) {
	limiter := New(0, 0)

	for range 100 {
		if !limiter.Allow("channel") {
			t.Fatal("limiting was applied despite a non-positive rate")
		}
	}
}

// Keys are webhook hashes. A stream of requests for non-existent channels would
// otherwise grow the map without bound.
func TestSweepDropsIdleBuckets(t *testing.T) {
	synctest.Test(t, func(t *testing.T) {
		limiter := New(1, 1)

		limiter.Allow("stale")

		time.Sleep(time.Hour)

		limiter.Allow("fresh")

		if removed := limiter.Sweep(30 * time.Minute); removed != 1 {
			t.Errorf("swept %d buckets, want 1", removed)
		}

		if limiter.Size() != 1 {
			t.Errorf("size = %d, want 1", limiter.Size())
		}
	})
}

func TestConcurrentUseIsSafe(t *testing.T) {
	limiter := New(1000, 1000)

	var waiting sync.WaitGroup
	for range 50 {
		waiting.Add(1)

		go func() {
			defer waiting.Done()

			for range 20 {
				limiter.Allow("channel")
				limiter.Sweep(time.Hour)
			}
		}()
	}

	waiting.Wait()
}
