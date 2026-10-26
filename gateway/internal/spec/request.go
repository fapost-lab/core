package spec

import (
	"bytes"
	"encoding/json"
	"io"
	"strings"
	"unicode/utf8"
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

	doc      any
	docKey   string
	docReady bool
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

// document returns the request document for a spec whose form field is form (nil for none),
// caching the outcome.
//
// The document is the JSON body when it decodes to an object or array, else the JSON held in the
// form field, else nothing (nil). Content-Type is never consulted. Decoding never fails the
// request: ingress must answer the provider even for a malformed payload, and an undecodable body
// simply yields no placeholder values.
//
// Numbers are decoded as json.Number so that an integer id survives without
// passing through a float, which would silently round values beyond 2^53.
func (r *Request) document(form *string) any {
	key := "\x00none"
	if form != nil {
		key = "f:" + *form
	}

	if r.docReady && r.docKey == key {
		return r.doc
	}

	r.docReady = true
	r.docKey = key
	r.doc = nil

	if decoded, ok := decodeContainer(r.RawBody); ok {
		r.doc = decoded

		return r.doc
	}

	if form == nil {
		return r.doc
	}

	value, ok := formValue(r.RawBody, *form)
	if !ok {
		return r.doc
	}

	if decoded, ok := decodeContainer(value); ok {
		r.doc = decoded
	}

	return r.doc
}

// decodeContainer decodes JSON, accepting only an object or an array, as PHP's is_array does.
func decodeContainer(data []byte) (any, bool) {
	// json_decode refuses malformed UTF-8; encoding/json would silently substitute U+FFFD.
	if !utf8.Valid(data) {
		return nil, false
	}

	decoder := json.NewDecoder(bytes.NewReader(data))
	decoder.UseNumber()

	var decoded any
	if err := decoder.Decode(&decoded); err != nil {
		return nil, false
	}

	// json_decode rejects trailing data; so must we.
	if _, err := decoder.Token(); err != io.EOF {
		return nil, false
	}

	switch decoded.(type) {
	case map[string]any, []any:
		return decoded, true
	default:
		return nil, false
	}
}

// formValue returns the decoded value of the first form segment named field.
//
// Hand-written because url.ParseQuery splits on ';' in older toolchains, drops a malformed pair and
// keeps scanning, none of which the PHP reference does. A segment whose name has an invalid escape
// is skipped; if the first matching segment has an invalid value escape the result is "absent".
func formValue(raw []byte, field string) ([]byte, bool) {
	for _, segment := range bytes.Split(raw, []byte("&")) {
		if len(segment) == 0 {
			continue
		}

		name, value := segment, []byte(nil)
		if eq := bytes.IndexByte(segment, '='); eq >= 0 {
			name, value = segment[:eq], segment[eq+1:]
		}

		decodedName, ok := decodeFormComponent(name)
		if !ok || string(decodedName) != field {
			continue
		}

		return decodeFormComponent(value)
	}

	return nil, false
}

// decodeFormComponent turns '+' into a space and %XX into a byte; a '%' not followed by two hex
// digits makes the component invalid.
func decodeFormComponent(component []byte) ([]byte, bool) {
	out := make([]byte, 0, len(component))

	for i := 0; i < len(component); i++ {
		switch c := component[i]; c {
		case '+':
			out = append(out, ' ')

		case '%':
			if i+2 >= len(component) {
				return nil, false
			}

			high, okHigh := unhex(component[i+1])
			low, okLow := unhex(component[i+2])

			if !okHigh || !okLow {
				return nil, false
			}

			out = append(out, high<<4|low)
			i += 2

		default:
			out = append(out, c)
		}
	}

	return out, true
}

func unhex(c byte) (byte, bool) {
	switch {
	case c >= '0' && c <= '9':
		return c - '0', true
	case c >= 'a' && c <= 'f':
		return c - 'a' + 10, true
	case c >= 'A' && c <= 'F':
		return c - 'A' + 10, true
	default:
		return 0, false
	}
}
