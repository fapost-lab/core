# Convenience wrapper around docker compose.
#
# Everything here is a shorthand for a command written out in
# docs/deployment/docker-compose.md. If the two ever disagree, the documentation
# is right — this file exists to save typing, not to hide behaviour.

# Compose interpolates ${...} from a file next to itself, so the project's .env
# has to be named explicitly. Every target carries the flag for that reason.
#
# ENV_FILE does the same job for the containers' own environment. The compose
# file defaults it to a .env beside itself, which is what a standalone
# installation has; a clone keeps the application's .env at the project root
# instead, and that is the one file both the containers and a local `php artisan`
# should read.
ENV := ENV_FILE=../.env
COMPOSE := $(ENV) docker compose --env-file ../.env

# Building requires the overlay: compose.yaml alone only ever pulls, because a
# service that declares a build is one Compose builds instead of fetching.
COMPOSE_BUILD := $(ENV) docker compose --env-file ../.env -f compose.yaml -f compose.build.yaml

# Development runs the same topology from a `dev` image with the source mounted.
# UID/GID are passed through so files the containers write stay yours.
COMPOSE_DEV := $(ENV) UID=$(shell id -u) GID=$(shell id -g) \
	docker compose --env-file ../.env -f compose.yaml -f compose.dev.yaml

.PHONY: help up up-built dev dev-down dev-shell dev-test down restart ps logs build shell artisan install doctor migrate horizon-restart pull clean

help: ## List available targets
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk -F':.*?## ' '{printf "  %-18s %s\n", $$1, $$2}'

up: ## Start the stack (add PROFILE=gateway to include the webhook gateway)
	$(COMPOSE) $(if $(PROFILE),--profile $(PROFILE),) up -d

down: ## Stop the stack, keeping volumes
	$(COMPOSE) down

restart: ## Restart every service
	$(COMPOSE) restart

ps: ## Show service status
	$(COMPOSE) ps

logs: ## Follow logs (SERVICE=app to narrow)
	$(COMPOSE) logs -f $(SERVICE)

build: ## Build images locally instead of pulling (needed when you ship your own Solutions)
	$(COMPOSE_BUILD) build $(SERVICE)

up-built: ## Start the stack from locally built images
	$(COMPOSE_BUILD) $(if $(PROFILE),--profile $(PROFILE),) up -d

dev: ## Start the development stack (source mounted, Vite in watch mode)
	$(COMPOSE_DEV) up -d --build
	@echo "app http://localhost:$${HTTP_PORT:-8000}   vite http://localhost:$${VITE_PORT:-5173}"

dev-down: ## Stop the development stack
	$(COMPOSE_DEV) down

dev-shell: ## Shell into the development application container
	$(COMPOSE_DEV) exec app bash

dev-test: ## Run the test suite inside the development container
	$(COMPOSE_DEV) exec app php artisan test $(ARGS)

shell: ## Open a shell in the application container
	$(COMPOSE) exec app bash

artisan: ## Run an artisan command, e.g. make artisan CMD="migrate --force"
	$(COMPOSE) exec app php artisan $(CMD)

install: ## Run the interactive installer inside the container
	$(COMPOSE) exec app php artisan platform:install

migrate: ## Apply landlord and tenant migrations
	$(COMPOSE) exec app php artisan migrate --database=landlord --path=database/migrations/landlord --force
	$(COMPOSE) exec app php artisan ops:tenants-migrate

doctor: ## Check that the gateway and the application see each other
	$(COMPOSE) exec app php artisan gateway:doctor

horizon-restart: ## Gracefully restart queue workers after a deploy
	$(COMPOSE) exec app php artisan horizon:terminate

pull: ## Pull base images
	$(COMPOSE) pull

clean: ## Stop the stack and delete its volumes — destroys the database
	@printf 'This deletes the database and all queued jobs. Type yes to continue: ' \
		&& read answer && [ "$$answer" = yes ] || exit 1
	$(COMPOSE) down --volumes
