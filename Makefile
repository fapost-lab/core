# FaPost Core — development tasks.
#
#     make            list every target and the command it runs
#     make test       run the suite
#
# A front-end, not a second source of truth. Where a Composer script holds a
# sequence — setup, dev, test, test:arch — this delegates to it, so there stays
# one definition of the sequence. Where the script is only a name for a single
# tool invocation, the tool is called directly: going through Composer to reach
# tools/cf-tunnel costs a process and hides what actually runs.
#
# The container stack has its own: make -C docker help

# ─── Running inside a container ──────────────────────────────────────────────
#
# Empty by default, so everything runs against whatever PHP is on PATH. Set
# CONTAINER to run inside a container instead — which is how this project is
# developed under devilbox:
#
#     make test CONTAINER=server-php-1
#
# Put it in .make.local to stop typing it; that file is git-ignored and included
# below. The working directory comes from `docker exec -w` rather than a
# wrapping shell, so arguments carrying spaces or quotes survive intact.
# Included first: EXEC is expanded immediately below, so a CONTAINER set after
# that point would be read too late and silently ignored.
-include .make.local

CONTAINER ?=
WORKDIR   ?= /shared/httpd/fapost-core
EXEC      := $(if $(CONTAINER),docker exec -w $(WORKDIR) $(CONTAINER),)

# The vhost the local site is served on. Devilbox names it after the directory,
# and the tunnel scripts append .test when the name carries no dot — so the
# directory name is the whole answer and nothing needs typing.
SITE ?= $(notdir $(CURDIR))

# A stable public hostname for a named Cloudflare tunnel, on a zone you control.
# Left empty you get a throwaway *.trycloudflare.com address that changes on
# every run — fine for a one-off, useless for a webhook you registered yesterday.
TUNNEL_HOST ?=

.DEFAULT_GOAL := help

.PHONY: help setup dev test test-filter test-arch stan lint fix \
        hooks-install dev-link ngrok tunnel docs-build docs-dev artisan shell \
        horizon queue-restart fresh \
        loadtest loadtest-stub-start loadtest-stub-stop loadtest-workers-start loadtest-workers-stop

# Prints each target with its description and the command that will actually
# run. The command is not written out here but asked of make itself, so it
# cannot drift from the recipe — and it shows the docker exec prefix when
# CONTAINER is set, which is the thing worth seeing before running anything.
help:
	@printf 'FaPost Core — development tasks.\n'
	@if [ -n "$(CONTAINER)" ]; then \
		printf 'Running inside container %s at %s.\n' '$(CONTAINER)' '$(WORKDIR)'; \
	else \
		printf 'Running locally. Add CONTAINER=<name> to run inside a container.\n'; \
	fi
	@printf '\n'
	@grep -hE '^[a-z][a-z-]*:.*?## ' $(MAKEFILE_LIST) \
		| sed 's/:[^#]*## /|/' \
		| while IFS='|' read -r target description; do \
			printf '  \033[1m%-14s\033[0m %s\n' "$$target" "$$description"; \
			$(MAKE) -n "$$target" 2>/dev/null \
				| grep -vE '^(make|\[|$$)' \
				| sed 's/^/                 /'; \
			printf '\n'; \
		done
	@printf 'The container stack is separate: make -C docker help\n'

# ─── Setup ───────────────────────────────────────────────────────────────────

setup: ## Install dependencies, create .env, migrate, build assets
	$(EXEC) composer run setup

hooks-install: ## Install the git hooks
	$(EXEC) php tools/git-hooks/install.php

dev-link: ## Symlink packages/* over vendor/ for local package development
	$(EXEC) php tools/dev-link-packages.php

# ─── Running ─────────────────────────────────────────────────────────────────

# Host-side deliberately, like ngrok and tunnel below. These bind ports and
# expect a terminal; running them through `docker exec` without a TTY mangles
# the interleaved output and fights whatever already serves the app.
dev: ## Serve, queue, logs and Vite together
	composer run dev

horizon: ## Run the queue workers
	$(EXEC) php artisan horizon

queue-restart: ## Let workers finish, then pick up new code
	$(EXEC) php artisan horizon:terminate

# ─── Testing ─────────────────────────────────────────────────────────────────

test: ## Run the PHPUnit suites
	$(EXEC) composer test

# The whole suite is the wrong feedback loop while working on one thing:
#     make test-filter FILTER=InstallPlatformCommandTest
test-filter: ## Run only tests matching FILTER=<name>
	@[ -n "$(FILTER)" ] || { echo 'Set FILTER, e.g. make test-filter FILTER=FlowEngineTest'; exit 1; }
	$(EXEC) php artisan test --compact --filter=$(FILTER)

test-arch: ## Run the PHPat architecture rules through PHPStan
	$(EXEC) composer run test:arch

# A prerequisite rather than a recursive call: make -n then shows the command
# that runs, not the path of the make binary that would run it.
stan: test-arch ## Alias for test-arch

artisan: ## Run an artisan command, e.g. make artisan CMD="tinker"
	@[ -n "$(CMD)" ] || { echo 'Set CMD, e.g. make artisan CMD="route:list"'; exit 1; }
	$(EXEC) php artisan $(CMD)

shell: ## Open a shell where the commands run
	@[ -n "$(CONTAINER)" ] || { echo 'No CONTAINER set — you are already in that shell.'; exit 1; }
	docker exec -it -w $(WORKDIR) $(CONTAINER) bash

# ─── Code style ──────────────────────────────────────────────────────────────

lint: ## Report style problems without changing anything
	$(EXEC) vendor/bin/pint --test

fix: ## Fix the style of the files you have changed
	$(EXEC) vendor/bin/pint --dirty

# ─── Database ────────────────────────────────────────────────────────────────

fresh: ## Drop every table and migrate from scratch — destroys local data
	@printf 'This drops every table in the local database. Type yes to continue: ' \
		&& read answer && [ "$$answer" = yes ] || exit 1
	$(EXEC) php artisan migrate:fresh

# ─── Webhooks in development ─────────────────────────────────────────────────

ngrok: ## Expose the local site through ngrok
	tools/ngrok-http $(SITE)

tunnel: ## Cloudflare tunnel to the local site — TUNNEL_HOST=<name> keeps the address
	tools/cf-tunnel $(SITE) $(TUNNEL_HOST)

# ─── Load testing ────────────────────────────────────────────────────────────
#
# Drives the flow engine under real concurrency — several tenants and
# contacts, real queue workers, real Redis locks — against a local Telegram
# Bot API stub, so it never reaches the real Telegram origin. See
# .ai/workspace/tasks/load-test/design.md for the design and
# docs/platform/ROADMAP.md for the "100 concurrent sessions" criterion this
# closes out.
#
# Tunable via make variables, e.g.:
#     make loadtest TENANTS=2 CONTACTS=10 MESSAGES=2 WORKERS=2

LOADTEST_PORT     ?= 8099
TENANTS           ?= 3
CONTACTS          ?= 100
MESSAGES          ?= 3
WORKERS           ?= 4
LOADTEST_URL      ?= http://127.0.0.1:$(LOADTEST_PORT)
LOADTEST_STUB_LOG ?= storage/logs/loadtest-stub.jsonl
LOADTEST_PID_DIR  ?= storage/app/loadtest

# Same $(EXEC) split as everywhere else, plus the two env vars every
# artisan/worker process in this run needs: TELEGRAM_API_BASE_URL so the
# Telegram client talks to the stub instead of the real API, and
# LOADTEST_STUB_LOG so the stub and `loadtest:run`/`loadtest:verify` agree on
# where outbound messages were logged.
LOADTEST_ENV := $(if $(CONTAINER),\
	docker exec -e TELEGRAM_API_BASE_URL=$(LOADTEST_URL) -e LOADTEST_STUB_LOG=$(LOADTEST_STUB_LOG) -w $(WORKDIR) $(CONTAINER),\
	env TELEGRAM_API_BASE_URL=$(LOADTEST_URL) LOADTEST_STUB_LOG=$(LOADTEST_STUB_LOG))

# One shell block, so the stub is stopped and the run is cleaned up whatever
# fails. A failed seed cleans with --all: a tenant that broke inside
# provisioning is not in the state file yet. Do not "dry run" this target with
# `make -n`: GNU Make still executes recipe lines that invoke $(MAKE).
loadtest: ## Full load-test run: stub, seed, workers, traffic, verify, clean (TENANTS/CONTACTS/MESSAGES/WORKERS)
	@STATUS=0; CLEAN=; \
	$(MAKE) loadtest-stub-start || exit $$?; \
	if $(LOADTEST_ENV) php artisan loadtest:seed --tenants=$(TENANTS) --contacts=$(CONTACTS); then \
		if $(MAKE) loadtest-workers-start; then \
			$(LOADTEST_ENV) php artisan loadtest:run --messages=$(MESSAGES) || STATUS=$$?; \
			if [ $$STATUS -eq 0 ]; then $(LOADTEST_ENV) php artisan loadtest:verify || STATUS=$$?; fi; \
		else STATUS=1; fi; \
		$(MAKE) loadtest-workers-stop; \
	else STATUS=1; CLEAN=--all; fi; \
	$(MAKE) loadtest-stub-stop; \
	$(EXEC) php artisan loadtest:clean $$CLEAN; \
	exit $$STATUS

# `php -S` is single-threaded per request unless PHP_CLI_SERVER_WORKERS asks
# for prefork workers — without it, concurrent seed/run traffic would queue
# up behind one PHP process. Backgrounded with nohup so it survives past this
# one `docker exec`/shell invocation; its pid is tracked for loadtest-stub-stop.
loadtest-stub-start:
	$(EXEC) sh -c 'mkdir -p $(LOADTEST_PID_DIR) $(dir $(LOADTEST_STUB_LOG)); \
		rm -f $(LOADTEST_STUB_LOG); \
		PHP_CLI_SERVER_WORKERS=4 LOADTEST_STUB_LOG=$(LOADTEST_STUB_LOG) \
			nohup php -S 127.0.0.1:$(LOADTEST_PORT) tools/loadtest/telegram-stub.php \
			> storage/logs/loadtest-stub-server.log 2>&1 & \
		echo $$! > $(LOADTEST_PID_DIR)/stub.pid'

# PHP_CLI_SERVER_WORKERS preforks: killing only the master pid captured by
# loadtest-stub-start leaves its worker children running (they inherit the
# listening socket and keep serving on their own). pkill -f matches the
# whole process family by command line instead of chasing pids.
loadtest-stub-stop:
	-$(EXEC) pkill -f 'php -S 127.0.0.1:$(LOADTEST_PORT) tools/loadtest/telegram-stub.php'
	-$(EXEC) rm -f $(LOADTEST_PID_DIR)/stub.pid

loadtest-workers-start:
	$(LOADTEST_ENV) sh -c 'mkdir -p $(LOADTEST_PID_DIR) && rm -f $(LOADTEST_PID_DIR)/worker-*.pid && \
		i=0; while [ $$i -lt $(WORKERS) ]; do \
			nohup php artisan queue:work redis --queue=flow.execution,messaging.transactional,messaging.logging \
				> storage/logs/loadtest-worker-$$i.log 2>&1 & \
			echo $$! > $(LOADTEST_PID_DIR)/worker-$$i.pid; \
			i=$$((i+1)); \
		done'

loadtest-workers-stop:
	-$(EXEC) sh -c 'for f in $(LOADTEST_PID_DIR)/worker-*.pid; do [ -f "$$f" ] && kill $$(cat "$$f") 2>/dev/null; done; rm -f $(LOADTEST_PID_DIR)/worker-*.pid'

# ─── Documentation ───────────────────────────────────────────────────────────

docs-build: ## Regenerate the Doctum PHP API reference into public/core
	$(EXEC) php tools/doctum/doctum.phar update tools/doctum/config.php --ignore-parse-errors

# Runs on the host, not in the container: the Mintlify CLI is a Node tool and
# the container has no Node. npx uses an installed `mint` when there is one
# and fetches it otherwise.
docs-dev: ## Preview docs.fapost.in locally from docs/site (Mintlify)
	cd docs/site && npx --yes mint dev
