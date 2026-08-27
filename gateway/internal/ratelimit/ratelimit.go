// Package ratelimit provides a per-key token bucket held in process memory.
//
// Kept out of Redis on purpose. The limiter's job is to shield Redis and the
// worker pool from a flood, and a limiter that needs a Redis round trip per
// request cannot shield Redis from anything. Each gateway instance therefore
// enforces its own share of the budget.
package ratelimit

import (
	"sync"
	"time"
)

// Limiter admits requests per key at a fixed rate with a burst allowance.
//
// Time is read straight from the time package rather than through an injected
// clock: tests drive it with testing/synctest, whose fake clock applies to the
// real calls. That keeps the seam out of the production type, where a settable
// clock is a field nothing but tests would ever write.
type Limiter struct {
	rate  float64
	burst float64

	mu      sync.Mutex
	buckets map[string]*bucket
}

type bucket struct {
	tokens   float64
	lastSeen time.Time
}

// New builds a limiter admitting `perSecond` requests per key, tolerating bursts
// of `burst`. A non-positive rate disables limiting entirely.
func New(perSecond float64, burst int) *Limiter {
	return &Limiter{
		rate:    perSecond,
		burst:   float64(burst),
		buckets: map[string]*bucket{},
	}
}

// Allow reports whether the key may proceed, consuming a token when it may.
func (l *Limiter) Allow(key string) bool {
	if l.rate <= 0 {
		return true
	}

	now := time.Now()

	l.mu.Lock()
	defer l.mu.Unlock()

	existing, ok := l.buckets[key]
	if !ok {
		l.buckets[key] = &bucket{tokens: l.burst - 1, lastSeen: now}

		return true
	}

	existing.tokens = min(l.burst, existing.tokens+now.Sub(existing.lastSeen).Seconds()*l.rate)
	existing.lastSeen = now

	if existing.tokens < 1 {
		return false
	}

	existing.tokens--

	return true
}

// Sweep drops buckets untouched for longer than idle.
//
// Keys are webhook hashes, so an unbounded map is a slow memory leak that a
// stream of requests for non-existent channels could also turn into a fast one.
func (l *Limiter) Sweep(idle time.Duration) int {
	cutoff := time.Now().Add(-idle)

	l.mu.Lock()
	defer l.mu.Unlock()

	removed := 0

	for key, existing := range l.buckets {
		if existing.lastSeen.Before(cutoff) {
			delete(l.buckets, key)
			removed++
		}
	}

	return removed
}

// Size reports how many buckets are tracked.
func (l *Limiter) Size() int {
	l.mu.Lock()
	defer l.mu.Unlock()

	return len(l.buckets)
}
