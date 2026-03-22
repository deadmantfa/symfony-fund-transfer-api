# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **Symfony PHP API** for secure fund transfers between accounts (homework assignment). Stack: PHP (latest), Symfony, MySQL, Redis.

See `Homework assignment.md` for the full spec. Submission requires a public GitHub repo with `composer.json`, Docker files, and a README covering installation, time spent, and AI tools used.

## Execution Environment — IMPORTANT

**All commands must run inside Docker, not on the host machine.**
The application requires PostgreSQL and Redis, which are only available inside the Docker network.
Running `php`, `composer`, etc. directly on the host will fail or use wrong environment variables.

Always prefix commands with `docker compose exec app`:

```bash
# Start all services
docker compose up -d

# Install dependencies
docker compose exec app composer install

# Run database migrations
docker compose exec app php bin/console doctrine:migrations:migrate

# Run all tests
docker compose exec app php bin/phpunit

# Run a single test class or method
docker compose exec app php bin/phpunit --filter TransferServiceTest
docker compose exec app php bin/phpunit --filter TransferServiceTest::testInsufficientFunds

# Code style check / fix
docker compose exec app composer cs-check
docker compose exec app composer cs-fix

# Static analysis
docker compose exec app composer phpstan

# Open a shell in the app container
docker compose exec app sh
```

## Architecture

### Domain

- **Account** — holds a balance; transfers debit/credit accounts atomically
- **Transfer** — the core write operation; must be idempotent and concurrency-safe
- Audit log of completed transfers for traceability

### Concurrency & Integrity

- Use **pessimistic locking** (`SELECT ... FOR UPDATE` via Doctrine's `LockMode::PESSIMISTIC_WRITE`) when reading account balances before debit/credit — prevents race conditions under load
- Wrap the full transfer in a single DB transaction; rollback on any failure
- Use **Redis** for: idempotency key caching (prevent duplicate processing) and rate limiting per account/user

### API Design

- RESTful JSON API, versioned under `/api/v1/`
- `422` for validation errors, `409` for conflicts (insufficient funds, duplicate request), `201` for created
- A `kernel.exception` listener normalizes all errors to consistent JSON — no stack traces exposed in responses

### Symfony Structure

```
src/
  Controller/     # Thin — delegate immediately to services
  Entity/         # Doctrine entities (Account, Transfer)
  Repository/     # Custom locking queries
  Service/        # TransferService: core business logic
  DTO/            # Typed request/response objects
  EventListener/  # JSON exception normalization
config/
migrations/
tests/
  Integration/    # Primary test layer — real DB via KernelTestCase/WebTestCase
  Unit/           # Service logic in isolation
```

### Testing

Integration tests are the primary mechanism. Tests must cover: happy path transfer, insufficient funds rejection, concurrent duplicate requests (idempotency), lock contention behavior. Reset DB state between tests using transactions or fixtures.
