# Daily commands for the local Docker stack (backlog P0.2-BE-05). First time: ./scripts/setup.sh
.PHONY: help up down logs sh migrate fresh test tinker docs lint fix integrity

help: ## List the commands
	@grep -E '^[a-z]+:.*## ' $(MAKEFILE_LIST) | awk -F ':.*## ' '{ printf "  make %-10s %s\n", $$1, $$2 }'

up: ## Start the stack and wait until it is healthy
	docker compose up -d --wait

down: ## Stop the stack (data is kept)
	docker compose down

logs: ## Follow the logs of every service
	docker compose logs -f --tail=100

sh: ## Shell inside the app container
	docker compose exec app bash

migrate: ## Run new migrations
	docker compose exec app php artisan migrate

fresh: ## Wipe the local database and recreate demo data (asks first)
	./scripts/reset-db.sh

test: ## Run the PHPUnit suite against the pluto_test database
	docker compose exec -T -e DB_DATABASE=pluto_test app php vendor/bin/phpunit

tinker: ## Laravel REPL
	docker compose exec app php artisan tinker

docs: ## Regenerate docs, contract and generated code from docs/source, then check them
	./scripts/docs.sh

lint: ## Code style check (Laravel Pint; `make fix` applies it)
	docker compose exec -T app vendor/bin/pint --test

fix: ## Apply the code style (Laravel Pint)
	docker compose exec -T app vendor/bin/pint

integrity: ## Books consistency check: balanced journals, stock = movements = inventory ledgers (exit 1 on a mismatch)
	docker compose exec -T app php artisan pluto:integrity
