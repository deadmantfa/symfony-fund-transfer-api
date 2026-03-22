.PHONY: keys up down shell logs worker-logs migrate seed seed-large \
        test test-unit test-integration coverage mutation \
        analyse cs-check cs-fix rector rector-fix deptrac audit \
        lint ci check-env explain hosts trust-ca docs load-test prod demo

keys: ## Generate JWT RS256 keypair (run once after cloning)
	docker compose run --rm app php bin/console lexik:jwt:generate-keypair --overwrite

up: ## Start all services in the background
	docker compose up -d

down:
	docker compose down

shell:
	docker compose exec app bash

logs:
	docker compose logs -f app

worker-logs:
	docker compose logs -f worker

migrate:
	docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

seed:
	docker compose exec app php bin/console app:seed --users=10 --accounts=20

seed-large:
	docker compose exec app php bin/console app:seed --users=10000 --accounts=50000

test:
	docker compose exec app ./vendor/bin/pest --no-coverage \
		--log-junit=docs/reports/test-results.xml

test-unit:
	docker compose exec app ./vendor/bin/pest tests/Unit --no-coverage

test-integration:
	docker compose exec app ./vendor/bin/pest tests/Integration --no-coverage

coverage:
	docker compose exec app ./vendor/bin/pest \
		--coverage-clover=docs/reports/clover.xml \
		--coverage-html=docs/reports/coverage

mutation:
	docker compose exec app ./vendor/bin/pest tests/Unit --mutate \
		--path=src/Domain,src/Application

analyse:
	docker compose exec app php -d memory_limit=512M vendor/bin/phpstan analyse

cs-check:
	docker compose exec app php vendor/bin/php-cs-fixer check --diff

cs-fix:
	docker compose exec app php vendor/bin/php-cs-fixer fix

rector:
	docker compose exec app php vendor/bin/rector process --dry-run

rector-fix:
	docker compose exec app php vendor/bin/rector process

deptrac:
	docker compose exec app php vendor/bin/deptrac analyse

audit:
	docker compose exec app composer audit

lint: cs-check analyse rector deptrac audit

ci: lint test

check-env:
	@docker info > /dev/null 2>&1 || (echo "Docker not running" && exit 1)
	@docker compose version > /dev/null 2>&1 || (echo "Docker Compose v2 required" && exit 1)
	@echo "✓ Environment ready"

explain:
	docker compose exec app php bin/console app:explain-queries > docs/reports/query-plans.md

## ── Local domain helpers ─────────────────────────────────────────────────────

hosts:
	@echo ""
	@echo "Add this line to /etc/hosts (requires sudo):"
	@echo ""
	@echo "  127.0.0.1  fund-transfer.local db.fund-transfer.local mail.fund-transfer.local"
	@echo ""
	@echo "Run:  sudo sh -c 'echo \"127.0.0.1 fund-transfer.local db.fund-transfer.local mail.fund-transfer.local\" >> /etc/hosts'"
	@echo ""

trust-ca:
	@echo "Exporting Caddy's local CA certificate..."
	docker compose exec -T app cat /data/caddy/pki/authorities/local/root.crt > /tmp/caddy-local-ca.crt
	@echo "Certificate saved to /tmp/caddy-local-ca.crt"
	@echo ""
	@echo "Run one of the following to trust it:"
	@echo ""
	@echo "  macOS:  sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain /tmp/caddy-local-ca.crt"
	@echo "  Linux:  sudo cp /tmp/caddy-local-ca.crt /usr/local/share/ca-certificates/caddy-local-ca.crt && sudo update-ca-certificates"
	@echo ""
	@echo "Then restart Chrome and reload https://fund-transfer.local"
	@echo ""

docs:
	@echo ""
	@echo "Swagger UI:  https://fund-transfer.local/api/docs"
	@echo "OpenAPI JSON: https://fund-transfer.local/api/docs.json"
	@echo ""
	@echo "Run 'make trust-ca' to install the local TLS certificate."
	@echo "Run 'make hosts'    to add the /etc/hosts entry."
	@echo ""

demo: ## Run end-to-end API demo — login, transfer, idempotency, FX, ledger (run 'make seed' first)
	docker compose exec app php bin/console app:demo

load-test: ## Run k6 load test against the local stack (run 'make prod && make seed' first)
	docker compose --profile load-test run --rm k6

prod: ## Start services in production mode (APP_ENV=prod, JIT enabled, no debug)
	docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build
