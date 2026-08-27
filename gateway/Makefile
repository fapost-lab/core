# Build targets for the FAPost webhook gateway.
#
# The gateway is a single static binary with no runtime dependencies, so a build
# on any machine produces something that runs on any Linux of the same
# architecture — no matching libc, no interpreter, nothing to install alongside.

BINARY      := gateway
BUILD_DIR   := bin
CMD         := ./cmd/gateway

# Version is stamped into the binary so a running process can be traced back to
# a commit. Falls back to a placeholder outside a git checkout, e.g. in a tarball.
VERSION     ?= $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)

# CGO off is what makes the binary static; -trimpath keeps build machine paths
# out of it; -s -w drop debug tables and roughly halve the size.
LDFLAGS     := -s -w -X main.version=$(VERSION)
GOFLAGS     := -trimpath
export CGO_ENABLED := 0

.PHONY: all build install-local clean test lint check dist help

all: check build ## Run checks then build for this machine

help: ## List available targets
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk -F':.*?## ' '{printf "  %-16s %s\n", $$1, $$2}'

build: ## Build for the host platform
	@mkdir -p $(BUILD_DIR)
	go build $(GOFLAGS) -ldflags="$(LDFLAGS)" -o $(BUILD_DIR)/$(BINARY) $(CMD)
	@echo "built $(BUILD_DIR)/$(BINARY) ($(VERSION))"

# Cross-compilation targets. Go needs no toolchain per target, so a release for
# every supported platform can be produced from a single developer machine.
dist: ## Cross-compile release binaries for common server platforms
	@mkdir -p $(BUILD_DIR)
	@for target in linux/amd64 linux/arm64 darwin/arm64; do \
		os=$${target%/*}; arch=$${target#*/}; \
		echo "building $$os/$$arch"; \
		GOOS=$$os GOARCH=$$arch go build $(GOFLAGS) -ldflags="$(LDFLAGS)" \
			-o $(BUILD_DIR)/$(BINARY)-$$os-$$arch $(CMD) || exit 1; \
	done
	@echo "release binaries in $(BUILD_DIR)/"

test: ## Run the test suite with the race detector
	go test -race ./...

lint: ## Verify formatting and run vet
	@unformatted=$$(gofmt -l .); \
	if [ -n "$$unformatted" ]; then echo "gofmt needed:"; echo "$$unformatted"; exit 1; fi
	go vet ./...

check: lint test ## Run lint and tests

install-local: build ## Install the binary into /usr/local/bin (may need sudo)
	install -m 0755 $(BUILD_DIR)/$(BINARY) /usr/local/bin/$(BINARY)
	@echo "installed /usr/local/bin/$(BINARY)"

clean: ## Remove build output
	rm -rf $(BUILD_DIR)
