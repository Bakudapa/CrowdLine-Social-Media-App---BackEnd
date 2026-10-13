# CrowdLine developer commands. Recipes run in /bin/sh (not fish), so they work
# from any shell. Everything PHP runs inside the app container.

COMPOSE := docker compose
APP     := $(COMPOSE) exec -T app
TEST_DB := crowdline_test
SHARD   ?= shard_0

.DEFAULT_GOAL := help
.PHONY: help setup up down build restart ps logs shell \
        migrate fresh test test-db lint fmt health psql reset

help: ## Show available commands
	@grep -hE '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

setup: ## First-time setup: .env, build, install deps, migrate
	@test -f .env || cp .env.example .env
	$(COMPOSE) up -d --build --wait
	$(APP) composer install --no-interaction
	@grep -q '^APP_KEY=base64' .env || $(APP) php artisan key:generate
	$(MAKE) migrate

up: ## Start the stack and wait until healthy
	$(COMPOSE) up -d --wait

down: ## Stop the stack (data volumes are kept)
	$(COMPOSE) down

build: ## Rebuild the app image
	$(COMPOSE) build app

restart: ## Restart app (e.g. after .env changes)
	$(COMPOSE) up -d --force-recreate app
	$(APP) php artisan config:clear

ps: ## Show container status
	$(COMPOSE) ps

logs: ## Follow app + nginx logs
	$(COMPOSE) logs -f app nginx

shell: ## Open a shell in the app container
	$(COMPOSE) exec app sh

migrate: ## Migrate global DB and every shard
	$(APP) php artisan migrate --force
	$(APP) php artisan shards:migrate

fresh: ## Drop and re-migrate global DB and every shard (DESTROYS dev data)
	$(APP) php artisan migrate:fresh --force
	$(APP) php artisan shards:migrate --fresh

test-db: ## Migrate the test database on global + every shard
	$(COMPOSE) exec -T -e DB_DATABASE=$(TEST_DB) app php artisan migrate:fresh --force -q
	$(COMPOSE) exec -T -e DB_DATABASE=$(TEST_DB) app php artisan shards:migrate --fresh -q

test: test-db ## Run the Pest suite (args: make test ARGS="--filter=Health")
	$(APP) php artisan config:clear -q
	$(APP) ./vendor/bin/pest $(ARGS)

lint: ## Check code style (Pint, no changes)
	$(APP) ./vendor/bin/pint --test

fmt: ## Fix code style (Pint)
	$(APP) ./vendor/bin/pint

health: ## Call the health endpoint
	@curl -s -w '\nHTTP %{http_code}\n' http://localhost:$${APP_PORT:-8080}/api/v1/health

psql: ## psql into a database (make psql SHARD=shard_1, or SHARD=pg_global)
	$(COMPOSE) exec $(SHARD) psql -U crowdline -d crowdline

reset: ## Remove containers AND volumes (DESTROYS all data), then setup again
	$(COMPOSE) down -v
	$(MAKE) setup
