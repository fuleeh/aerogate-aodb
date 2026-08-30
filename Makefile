.DEFAULT_GOAL := help

.PHONY: help setup build up down logs install test analyse format lint quality db-migrate db-fresh schedule-list

help: ## List available commands
	@awk 'BEGIN {FS = ":.*## "; printf "Usage: make <target>\n\n"} /^[a-zA-Z_-]+:.*## / {printf "  %-16s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

setup: ## Build, start, configure, and migrate the application
	@test -f .env || cp .env.example .env
	docker compose build
	docker compose up -d
	docker compose exec app php artisan key:generate --force
	docker compose exec app php artisan migrate --force

build: ## Build the application image
	docker compose build

up: ## Start application services
	docker compose up -d

down: ## Stop application services
	docker compose down

logs: ## Follow application service logs
	docker compose logs --follow app

install: ## Install PHP dependencies in the application container
	docker compose run --rm app composer install

test: ## Run the test suite
	docker compose exec app php artisan test

analyse: ## Run Larastan/PHPStan static analysis
	docker compose exec app composer analyse

format: ## Format PHP code using the PSR-12 preset
	docker compose exec app composer format

lint: ## Check PHP formatting
	docker compose exec app composer format:test

quality: ## Run formatting, static analysis, and tests
	docker compose exec app composer quality

db-migrate: ## Apply pending database migrations
	docker compose exec app php artisan migrate

db-fresh: ## Rebuild and seed the development database
	docker compose exec app php artisan migrate:fresh --seed

schedule-list: ## Show registered scheduled tasks
	docker compose exec app php artisan schedule:list
