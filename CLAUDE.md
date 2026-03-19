# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **Symfony PHP API** for secure fund transfers between accounts (homework assignment). Stack: PHP (latest), Symfony, MySQL, Redis.

See `Homework assignment.md` for the full spec. Submission requires a public GitHub repo with `composer.json`, Docker files, and a README covering installation, time spent, and AI tools used.

## Common Commands

```bash
# Install dependencies
composer install

# Start services
docker compose up -d

# Run database migrations
php bin/console doctrine:migrations:migrate

# Run all tests
php bin/phpunit

# Run a single test class or method
php bin/phpunit --filter TransferServiceTest
php bin/phpunit --filter TransferServiceTest::testInsufficientFunds

# Code style check / fix
composer cs-check
composer cs-fix

# Static analysis
composer phpstan
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
