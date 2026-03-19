# Fund Transfer API — Design Document

**Date:** 2026-03-19
**Status:** Approved

---

## Overview

A production-grade, secure REST API for fund transfers between multi-currency accounts. Built with PHP 8.5 + Symfony, PostgreSQL 16, Redis 7, and FrankenPHP. Fully async transfer processing via Symfony Messenger. Compliant with OWASP Top 10, PCI-DSS v4.0, GDPR, and FAPI 2.0.

---

## Technology Decisions

| Concern | Choice | Rationale |
|---|---|---|
| Runtime | FrankenPHP | Native Symfony support, single binary, Caddy-based TLS 1.3, simpler Docker setup than PHP-FPM + Nginx |
| Database | PostgreSQL 16 | Superior row-level locking (`FOR UPDATE SKIP LOCKED`), `DECIMAL` precision, range partitioning — objectively better than MySQL for financial workloads |
| Cache/Queue | Redis 7 | Idempotency key store, FX rate cache, Messenger transport, rate limiter backend — single service, multiple roles |
| Money math | `brick/money` + `BigDecimal` | Floats are forbidden everywhere. `DECIMAL(20,8)` in DB, `BigDecimal` in PHP |
| Error format | RFC 9457 Problem Details | IETF standard for HTTP API errors (`application/problem+json`). Used by Stripe, GitHub, Microsoft |
| API Docs | NelmioApiDocBundle + Redoc | OpenAPI 3.1 spec generated from PHP attributes. Redoc renders a professional single-page UI. Spec exportable to Postman |

---

## Architecture: DDD with Application Services

```
src/
  Controller/Api/V1/          # HTTP only — validate, call app service, return response
    AuthController.php
    AccountController.php
    TransferController.php
    FxRateController.php

  Application/                # Use cases — one class per operation
    Transfer/
      InitiateTransferCommand.php
      InitiateTransferHandler.php   # Messenger handler (HTTP side)
      ProcessTransferCommand.php
      ProcessTransferHandler.php    # Messenger handler (worker side)
    Account/
      CreateAccountCommand.php
      CreateAccountHandler.php
      # ... per operation

  Domain/                     # Pure PHP — zero Symfony/Doctrine imports
    Account/
      Account.php             # Entity with balance mutation methods
      AccountRepository.php   # Interface
    Transfer/
      Transfer.php            # Entity + status machine
      TransferRepository.php  # Interface
    FxRate/
      FxRateProviderInterface.php
      FxRateService.php       # Interface
      Money.php               # Value object: BigDecimal + currency

  Infrastructure/             # Implements domain interfaces
    Persistence/
      DoctrineAccountRepository.php
      DoctrineTransferRepository.php
      DoctrineLedgerEntryRepository.php  # append-only, no update/delete
    FxRate/
      CachingFxRateService.php           # Redis → Frankfurter → DB fallback
      FrankfurterFxRateProvider.php      # implements FxRateProviderInterface
      StubFxRateProvider.php             # deterministic rates for tests
    Messenger/
      Transport/                         # Redis transport config

  Security/
    Voter/
      AccountVoter.php        # ownership checks
      TransferVoter.php

config/
migrations/
tests/
  Unit/                       # Domain + Application — zero framework
  Integration/                # Real PostgreSQL + Redis via WebTestCase
docs/
  plans/                      # design documents
  reports/                    # coverage, mutation, query plans (CI-generated)
```

**Layer rule:** Domain has zero Symfony/Doctrine imports. Infrastructure implements Domain interfaces. Controllers never touch repositories directly. Enforced by Deptrac + Pest `arch()` tests.

---

## Data Model

### `users`
```sql
id UUID PK, email (unique), first_name, last_name,
phone_number, date_of_birth, address JSONB,
password_hash, email_verified_at,
kyc_status ENUM(unverified/pending/verified),
status ENUM(active/suspended/closed),
terms_accepted_at, created_at, updated_at
```
PII fields (`date_of_birth`, `address`, `phone_number`) encrypted at rest with libsodium.

### `accounts`
```sql
id UUID PK, user_id FK, currency CHAR(3),
balance DECIMAL(20,8), name,
status ENUM(active/frozen/closed),
created_at, updated_at

UNIQUE (user_id, currency)
INDEX (user_id, status)
```

### `transfers` *(range-partitioned monthly on created_at)*
```sql
id UUID PK,
from_account_id FK, to_account_id FK,
amount DECIMAL(20,8), currency CHAR(3),       -- canonical
from_amount DECIMAL(20,8), from_currency,      -- actual debit after FX
to_amount DECIMAL(20,8), to_currency,          -- actual credit after FX
fx_rate DECIMAL(20,8), fx_rate_provider,       -- audit trail
status ENUM(pending/processing/completed/failed),
idempotency_key (unique), failure_reason,
initiated_by_user_id FK, ip_address, user_agent,
created_at, updated_at

INDEX (from_account_id, status, created_at)
INDEX (idempotency_key)
```

### `ledger_entries` *(range-partitioned monthly — immutable, no UPDATE ever)*
```sql
id UUID PK, transfer_id FK, account_id FK,
entry_type ENUM(debit/credit),
amount DECIMAL(20,8), currency CHAR(3),
balance_before DECIMAL(20,8), balance_after DECIMAL(20,8),
created_at

INDEX (account_id, created_at)
INDEX (transfer_id)
```
Double-entry: every transfer produces exactly 2 ledger entries. Balance always reconstructable from ledger.

### `audit_logs` *(range-partitioned monthly — append-only)*
```sql
id UUID PK, entity_type, entity_id UUID,
action, actor_id FK (nullable),
old_value JSONB, new_value JSONB,
ip_address, user_agent, created_at

INDEX (entity_type, entity_id, created_at)
INDEX (actor_id, created_at)
```

### `fx_rates` *(last-known fallback)*
```sql
id UUID PK, base_currency CHAR(3), target_currency CHAR(3),
rate DECIMAL(20,8), provider, fetched_at

UNIQUE (base_currency, target_currency, provider)
INDEX (base_currency, target_currency, fetched_at DESC)
```

---

## API Contract

**Base:** `/api/v1/` · **Auth:** `Authorization: Bearer <jwt>` on all except register/login

### Endpoints
```
POST   /api/v1/auth/register
POST   /api/v1/auth/login
POST   /api/v1/auth/refresh
POST   /api/v1/auth/logout

GET    /api/v1/users/me
PATCH  /api/v1/users/me
DELETE /api/v1/users/me
GET    /api/v1/users/me/export          # GDPR data portability

GET    /api/v1/accounts                 # cursor-paginated
POST   /api/v1/accounts
GET    /api/v1/accounts/{id}
PATCH  /api/v1/accounts/{id}
DELETE /api/v1/accounts/{id}            # only if balance = 0
GET    /api/v1/accounts/{id}/ledger     # cursor-paginated, filterable by date

POST   /api/v1/transfers                # → 202 Accepted
GET    /api/v1/transfers                # cursor-paginated, filterable
GET    /api/v1/transfers/{id}
DELETE /api/v1/transfers/{id}           # cancel if pending

GET    /api/v1/fx-rates
GET    /api/v1/fx-rates/{from}/{to}
```

### HTTP Semantics
| Scenario | Status |
|---|---|
| Transfer initiated | `202 Accepted` + `Location` header |
| Validation failure | `422 Unprocessable Entity` (RFC 9457) |
| Duplicate idempotency key | `200 OK` + original transfer |
| Insufficient funds | `409 Conflict` (RFC 9457) |
| Account frozen/closed | `409 Conflict` (RFC 9457) |
| KYC not verified | `403 Forbidden` (RFC 9457) |
| Account not owned by JWT user | `403 Forbidden` (RFC 9457) |
| Rate limited | `429 Too Many Requests` + `Retry-After` |

Pagination: **cursor-based** on all list endpoints — O(1) regardless of dataset size.

---

## Async Transfer Flow

```
POST /api/v1/transfers
  → validate (JWT ownership, KYC, account status)
  → check idempotency key (Redis)
  → check server-side dedup (same params within 60s)
  → create Transfer(status: pending)
  → cache idempotency key in Redis (TTL: 24h)
  → dispatch ProcessTransferCommand to Redis queue
  → return 202 Accepted + Location header

Worker: ProcessTransferHandler
  → lock transfer row (FOR UPDATE) → status: processing
  → lock both account rows (ascending UUID order → deadlock prevention)
  → validate sufficient balance
  → fetch FX rate (Redis → Frankfurter API → DB fallback)
  → calculate from_amount + to_amount
  → debit from_account.balance
  → credit to_account.balance
  → write 2 immutable ledger entries
  → set transfer status: completed
  → write audit_log entry
  → commit

Client polls GET /api/v1/transfers/{id} until status = completed/failed
```

### Failure Behaviour
| Failure | Behaviour |
|---|---|
| FX unavailable | Retry 3× exponential backoff → DB fallback |
| Insufficient funds | status: failed, no retry |
| Account frozen mid-flight | status: failed, no retry |
| DB deadlock | Messenger auto-retries (3×: 1s/5s/15s) |
| Worker crash | Transaction rolls back, Messenger redelivers |

---

## FX Rate Service

```
getRate(from, to):
  1. Same currency? → return 1.0
  2. Redis cache (key: fx_rate:{FROM}:{TO}, TTL: 3600s) → HIT: return
  3. Frankfurter API → SUCCESS: store Redis + upsert DB → return
  4. DB fallback (most recent rate) → log warning → return
  5. FxRateUnavailableException → transfer fails
```

`FxRateProviderInterface` allows swapping providers. Frankfurter is the default — no API key, ECB data, zero setup friction. Documented as extension point in README.

---

## Security

### JWT (FAPI 2.0 aligned)
- RS256 asymmetric signing — private key signs, public key verifiable by other services
- Access token: 15 minutes · Refresh token: 30 days, rotated on each use
- Claims: `sub`, `email`, `kyc_status`, `roles`, `jti` (replay prevention), `iss`, `aud`
- LexikJWTAuthenticationBundle + JWTRefreshTokenBundle

### OWASP Top 10
| # | Mitigation |
|---|---|
| A01 Broken Access Control | Symfony Voters on every mutation; ownership from JWT, never client input |
| A02 Cryptographic Failures | Argon2id passwords; libsodium PII encryption; TLS 1.3; RS256 JWT |
| A03 Injection | Doctrine parameterized queries only; Symfony Validator on all inputs; amounts as decimal strings |
| A04 Insecure Design | Double-entry ledger; pessimistic locking; idempotency; KYC gating |
| A05 Security Misconfiguration | `.env.local` for secrets; prod disables debug/profiler; security headers via Caddy |
| A06 Vulnerable Components | `composer audit` in CI; Dependabot on GitHub |
| A07 Auth Failures | Rate limiting; refresh token rotation; account lockout (10 failures / 15min) |
| A08 Data Integrity | `composer.lock` committed; ledger immutable; audit log append-only |
| A09 Security Logging | `audit_logs` — actor, IP, user agent, old/new values, 12-month retention |
| A10 SSRF | FX provider URL is a fixed constant, never user-configurable |

### PCI-DSS v4.0 (relevant controls)
*Scope note: this system does not process card data — PCI-DSS certification not required. Controls implemented as best practice.*

| Requirement | Implementation |
|---|---|
| Req 3 — Stored data | libsodium encryption on PII; `APP_ENCRYPTION_KEY` in env |
| Req 4 — Data in transit | TLS 1.3 enforced by FrankenPHP/Caddy |
| Req 7 — Least privilege | DB user has no DDL in production; read-only user for reporting |
| Req 8 — Strong auth | Argon2id (memory: 64MB, iterations: 4); HaveIBeenPwned check on registration |
| Req 10 — Logging | Append-only audit log; 12-month retention via partition archival |
| Req 10.3 — Log integrity | No UPDATE/DELETE grants on audit_logs; no delete method in repository |
| Req 11 — Security testing | Infection PHP mutation testing; `composer audit` in CI |

### GDPR
| Requirement | Implementation |
|---|---|
| Right to erasure | Anonymise PII on `DELETE /users/me`; retain financial records |
| Data portability | `GET /users/me/export` returns all user data as JSON |
| Data minimisation | Only collect required fields |
| Consent | `terms_accepted_at` on users table |
| Encryption at rest | libsodium on all PII fields |

### Password Policy
- Argon2id: `memory_cost: 65536`, `time_cost: 4`, `threads: 1` (OWASP minimum)
- Minimum 12 characters
- HaveIBeenPwned k-anonymity check on registration

### Security Headers (Caddy)
```
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Content-Security-Policy: default-src 'none'
Referrer-Policy: no-referrer
Permissions-Policy: geolocation=(), microphone=()
```

### Rate Limiting (Symfony RateLimiter + Redis)
| Endpoint | Limit |
|---|---|
| `POST /auth/login` | 10 req / 15 min per IP |
| `POST /auth/register` | 5 req / hour per IP |
| `POST /transfers` | 30 req / min per user |
| All authenticated | 200 req / min per user |

---

## PHP 8.5 Standards

Every file: `declare(strict_types=1);` — enforced by PHP-CS-Fixer, fails CI if missing.

- `readonly` classes for all Value Objects and DTOs
- Property hooks for computed properties
- Asymmetric visibility (`public private(set)`) for entity state
- Backed Enums for all status/type fields — no string constants
- `array_find()`, `array_any()`, `array_all()` over manual loops
- First-class callable syntax
- `never` return type on methods that always throw
- `#[\Deprecated]` attribute on scheduled removals
- Fibers for concurrent FX pair fetching
- `brick/money` + `BigDecimal` — floats forbidden at every layer

Rector configured to `PhpVersion::PHP_85` — enforces idioms automatically in CI.

---

## Testing Strategy

**Philosophy:** TDD always. Red → Green → Refactor. No production code without a failing test.

### Coverage: 100% line + branch across all layers

```
tests/
  Unit/                         # Domain + Application, zero framework
    Domain/AccountTest.php
    Domain/TransferTest.php
    Domain/MoneyTest.php
    Application/InitiateTransferHandlerTest.php
    Application/ProcessTransferHandlerTest.php

  Integration/                  # Real PostgreSQL + Redis
    Api/AuthControllerTest.php
    Api/AccountControllerTest.php
    Api/TransferControllerTest.php
    Api/FxRateControllerTest.php
    Concurrency/PessimisticLockTest.php
    Concurrency/IdempotencyTest.php
    Concurrency/DeadlockPreventionTest.php
    Concurrency/InsufficientFundsRaceTest.php
    Security/AuthorizationTest.php
    Security/RateLimitTest.php
    Security/KycGatingTest.php
    Security/OWASPTest.php
```

### Architecture Tests (Pest `arch()`)
- Domain has no Symfony/Doctrine imports
- Controllers never touch repositories directly
- Ledger repository has no update/delete methods
- No floats anywhere in the codebase
- All handlers are `final`

### Mutation Testing (Infection PHP)
- MSI: 100% on Domain + Application layers
- Runs as final CI step — build fails below threshold

### Reports (committed to `docs/reports/`, generated by CI)
```
docs/reports/
  coverage/index.html         # browsable HTML coverage
  mutation-report.html        # Infection PHP results
  test-results.xml            # JUnit for GitHub test summary
  query-plans.md              # EXPLAIN ANALYZE on hot paths
```

### Seed Data
```bash
make seed-large   # 10k users, 50k accounts, 1M transfers across all partitions
```
Doctrine DBAL bulk insert — bypasses ORM for speed (~50k rows/sec).

---

## Docker & Developer Experience

### Services
```yaml
app:       FrankenPHP (HTTP + healthcheck)
postgres:  PostgreSQL 16 (healthcheck)
redis:     Redis 7 Alpine (healthcheck)
worker:    FrankenPHP image — Messenger consumer only
mailpit:   Local SMTP catcher (email verification)
```
All services have healthchecks. `app` waits for `postgres` and `redis` before starting.

### Makefile (single entry point)
```makefile
make up / make down
make migrate / make seed / make seed-large
make test / make test-unit / make test-integration
make coverage / make mutation
make analyse / make cs-check / make cs-fix
make rector / make rector-fix / make deptrac / make audit
make lint        # all quality gates
make ci          # lint + test (reproduces CI locally)
make shell / make logs / make worker-logs
make explain     # EXPLAIN ANALYZE → docs/reports/query-plans.md
make check-env   # verifies Docker version + available ports
```

### Cross-platform
| OS | Requirement |
|---|---|
| macOS | Docker Desktop 4.x+, Apple Silicon fully supported |
| Linux | Docker Engine 24+ + Compose v2, add user to `docker` group |
| Windows | WSL2 required, all commands run inside WSL2 terminal |

---

## CI/CD Pipeline (GitHub Actions)

```
Stage 1: Quality Gates (parallel, no Docker)
  PHPStan level 9 · PHP-CS-Fixer · Rector · Deptrac · composer audit

Stage 2: Unit Tests + Arch Tests
  Pest unit suite · arch() rules · 100% coverage enforced

Stage 3: Integration Tests (Docker)
  Spin up PostgreSQL + Redis · migrations · full integration suite · coverage merged

Stage 4: Mutation Testing
  Infection PHP · MSI 100% enforced

Stage 5: Reports
  Upload coverage, mutation report, test results, query plans as artifacts
```

---

## Future Improvements (documented in README)

- Full OAuth2 / PKCE flow (FAPI 2.0 complete)
- Read replicas for reporting queries
- Webhook notifications on transfer completion
- Admin panel (account management, KYC review)
- Multi-region deployment with cross-region transfer routing
- `composer show --format=json` SBOM generation for supply chain security
