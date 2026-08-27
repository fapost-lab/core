# Build and package the webhook gateway.
#
# Two stages so the shipped image contains the binary and nothing else: no Go
# toolchain, no shell, no package manager. That keeps the image small and leaves
# an attacker who reaches the most exposed process in the stack with no tools.

FROM golang:1.27-alpine AS build

WORKDIR /src

# Dependencies first: this layer is rebuilt only when go.mod or go.sum change,
# not on every source edit.
COPY go.mod go.sum ./
RUN go mod download

COPY . .

ARG VERSION=dev

# CGO off produces a static binary, which is what allows the scratch stage below.
RUN CGO_ENABLED=0 go build \
    -trimpath \
    -ldflags="-s -w -X main.version=${VERSION}" \
    -o /out/gateway ./cmd/gateway

FROM scratch

# Root certificates are the one thing the binary still needs from a distribution:
# without them the proxy fallback cannot verify TLS when the application is
# reached over HTTPS.
COPY --from=build /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/
COPY --from=build /out/gateway /gateway

# Unprivileged by number, since scratch has no /etc/passwd to name a user in.
USER 65534:65534

EXPOSE 8080

# No .env inside the image: configuration is injected by the runtime, so one
# image is promoted unchanged from staging to production.
ENTRYPOINT ["/gateway", "--env", ""]
