.PHONY: up down shell logs worker-logs migrate seed seed-large \
        test test-unit test-integration coverage mutation \
        analyse cs-check cs-fix rector rector-fix deptrac audit \
        lint ci check-env explain

up:
	docker compose up -d --wait

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
	docker compose exec app php bin/console app:seed --users=10 --accounts=20 --transfers=100

seed-large:
	docker compose exec app php bin/console app:seed --users=10000 --accounts=50000 --transfers=1000000

test:
	docker compose exec app php bin/pest --coverage-clover=docs/reports/clover.xml \
		--coverage-html=docs/reports/coverage \
		--log-junit=docs/reports/test-results.xml

test-unit:
	docker compose exec app php bin/pest tests/Unit --no-coverage

test-integration:
	docker compose exec app php bin/pest tests/Integration

coverage:
	docker compose exec app php bin/pest --coverage-html=docs/reports/coverage

mutation:
	docker compose exec app php vendor/bin/infection \
		--threads=4 \
		--min-msi=100 \
		--min-covered-msi=100 \
		--html-log-file=docs/reports/mutation-report.html

analyse:
	docker compose exec app php vendor/bin/phpstan analyse

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
