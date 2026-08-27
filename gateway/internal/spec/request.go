package spec

import (
	"bytes"
	"encoding/json"
	"strings"
)

// Request is the transport-agnostic view a spec operates on.
//
// The body is kept as the bytes that arrived: HMAC schemes sign what the provider
// actually sent, and decoding then re-encoding would reorder keys and change
// escaping, invalidating every signature.
type Request struct {
	RawBody []byte

	headers map[string]string
	query   map[string]string

	body            any
	decodeAttempted bool
}

// NewRequest builds a request, normalizing header names for case-insensitive lookup.
func NewRequest(rawBody []byte, headers map[string]string, query map[string]string) *Request {
	normalized := make(map[string]string, len(headers))

	for name, value := range headers {
		normalized[strings.ToLower(name)] = value
	}

	if query == nil {
		query = map[string]string{}
	}

	return &Request{RawBody: rawBody, headers: normalized, query: query}
}

// Header returns a header value, or an empty string when absent.
func (r *Request) Header(name string) string {
	return r.headers[strings.ToLower(name)]
}

// QueryParam returns a query parameter value, or an empty string when absent.
func (r *Request) QueryParam(name string) string {
	return r.query[name]
}

// body decodes the request body lazily, caching the outcome.
//
// Decoding never fails the request: ingress must answer the provider even for a
// malformed payload, and an undecodable body simply yields no placeholder values.
//
// Numbers are decoded as json.Number so that an integer id survives without
// passing through a float, which would silently round values beyond 2^53.
func (r *Request) decodedBody() any {
	if r.decodeAttempted {
		return r.body
	}

	r.decodeAttempted = true

	decoder := json.NewDecoder(bytes.NewReader(r.RawBody))
	decoder.UseNumber()

	var decoded any
	if err := decoder.Decode(&decoded); err != nil {
		return nil
	}

	r.body = decoded

	return r.body
}
