# Fund Transfer API

A secure, production-ready Symfony API for transferring funds between accounts. Built as a homework assignment to demonstrate scalable financial system design.

---

## Tech Stack

- **PHP 8.4** · **Symfony 7** · **FrankenPHP** (worker mode runtime)
- **PostgreSQL 16** — primary persistence (chosen over MySQL for superior `SELECT … FOR UPDATE` pessimistic locking semantics, native UUID type, and stronger ACID guarantees — directly relevant to financial integrity)
- **Redis 7** — idempotency key caching, Messenger transport
- **Doctrine DBAL** (no ORM — raw SQL with typed domain objects)
- **Symfony Messenger** — async transfer processing via worker
- **brick/money** — arbitrary-precision decimal arithmetic for money
- **LexikJWTAuthenticationBundle** — RS256 JWT, stateless auth
- **libsodium** — field-level encryption (PII at rest)

---

## Architecture

The project follows a strict layered DDD structure enforced by [Deptrac](https://github.com/deptrac/deptrac):

```
src/
  Controller/Api/V1/   # Thin HTTP layer — validates input, delegates, returns JSON
  Application/         # Use-case handlers (InitiateTransfer, ProcessTransfer, etc.)
  Domain/              # Pure business logic: Account, Transfer, Money, User
  Infrastructure/      # Doctrine repositories, FX rate provider, encryption
  EventListener/       # RFC 9457 Problem Details normalizer + correlation ID propagation
  Monolog/             # CorrelationIdProcessor — injects X-Request-ID into every log record
  Security/            # JWT user provider, authorization voters
```

**Key design decisions:**

- **Pessimistic locking** — accounts are locked with `SELECT ... FOR UPDATE` in ascending UUID order (deadlock prevention) before any debit/credit
- **Double-entry ledger** — every transfer creates two `LedgerEntry` rows (debit + credit) for full auditability and reconcilability
- **Idempotency** — Redis-backed, scoped per user (`userId.rawKey`), with DB-level duplicate detection as a secondary guard if Redis is unavailable
- **Async processing** — transfers are created as `pending` and dispatched to a Symfony Messenger worker via Redis; Messenger retries failed jobs with exponential back-off
- **Cross-currency** — FX rates fetched from Frankfurter API, cached in Redis + DB; `Money::convertTo()` handles currency conversion without precision loss
- **KYC gating** — users must have `kyc_status = verified` + verified email before initiating transfers
- **GDPR export** — `GET /api/v1/me/export` returns all user data + transfer history as JSON
- **Structured logging** — Monolog with JSON formatter to stdout (Docker-friendly); dedicated `transfer` and `security` channels; every log record carries an `X-Request-ID` correlation ID for end-to-end tracing

---

## Security

| Concern | Implementation |
|---|---|
| Authentication | RS256 JWT (asymmetric keys) via LexikJWTAuthenticationBundle |
| Password hashing | Argon2id, memory_cost 65536, time_cost 4 |
| Authorisation | Symfony Voters — `AccountVoter`, `TransferVoter` enforce ownership per resource |
| Race conditions | Pessimistic `SELECT … FOR UPDATE` locks in ascending UUID order (prevents deadlocks) |
| Double-spend | Idempotency keys (Redis) + server-side dedup window (DB) |
| PII at rest | libsodium `secretbox` field-level encryption (XSalsa20-Poly1305) |
| Injection | DBAL parameterised queries throughout — no string concatenation |
| Error exposure | RFC 9457 Problem Details — no stack traces in any response |
| Rate limiting | Symfony RateLimiter on registration endpoint |
| KYC compliance | Transfer initiation gated on `kyc_status = verified` |
| OWASP | Integration tests cover injection, 404 shape, malformed JSON |

Known gap documented intentionally: JWT blocklist not implemented — deleted users' tokens remain valid until TTL expiry.

---

## Quick Start

### Prerequisites

- Docker + Docker Compose v2

### 1. Clone and configure

```bash
git clone <repo-url>
cd fund-transfer-api
cp .env .env.local
```

### 2. Add the local domains to `/etc/hosts`

All local services run under `fund-transfer.local` subdomains with HTTPS. Add all four entries in one line:

```bash
make hosts   # prints the exact line to add, then run:
sudo sh -c 'echo "127.0.0.1 fund-transfer.local api.fund-transfer.local db.fund-transfer.local mail.fund-transfer.local" >> /etc/hosts'
```

| URL | Service |
|-----|---------|
| `https://api.fund-transfer.local` | API + Swagger UI (primary) |
| `https://fund-transfer.local` | Alias — resolves to the same app |
| `https://db.fund-transfer.local` | pgweb database browser (auto-connects) |
| `https://mail.fund-transfer.local` | Mailpit SMTP catcher |

### 3. Start services

```bash
make up
```

This builds the image and starts all containers in the background:

| Container | Role |
|-----------|------|
| `app` | FrankenPHP + Caddy — API at `https://api.fund-transfer.local` |
| `worker` | Symfony Messenger consumer for async transfer processing |
| `postgres` | PostgreSQL 16 |
| `redis` | Redis 7 |
| `mailpit` | Local SMTP catcher at `https://mail.fund-transfer.local` |
| `pgweb` | Database browser at `https://db.fund-transfer.local` — auto-connects, no login required |

> First boot takes ~30 seconds while the image builds and Caddy generates its TLS certificate. Run `make logs` to follow progress.

### 4. Generate JWT keypair

```bash
make keys
```

This writes `config/jwt/private.pem` and `config/jwt/public.pem`. Only needed once after cloning.

### 5. Run migrations

```bash
make migrate
```

Creates and migrates both the dev database (`fund_transfer`) and the test database (`fund_transfer_test`) in one step. Safe to re-run — idempotent.

### 6. Trust the local TLS certificate

Caddy auto-generates a certificate via its own local CA. Install it so that `curl` and the browser accept HTTPS without warnings:

```bash
make trust-ca   # exports the Caddy CA cert to /tmp/caddy-local-ca.crt

# macOS — install and trust system-wide
sudo security add-trusted-cert -d -r trustRoot \
  -k /Library/Keychains/System.keychain \
  /tmp/caddy-local-ca.crt
```

Then restart Chrome/Safari and open `https://api.fund-transfer.local`.

> **Skipping this step is fine** — pass `-k` to curl (skip verification) or click **Advanced → Proceed** in the browser.

### 7. Seed test data and run the demo

```bash
make seed   # 10 KYC-verified users, funded accounts in 5 currencies, FX rate rows
make demo   # end-to-end demo: login → transfers → async worker → idempotency check
```

`make demo` calls the live API and shows the full flow in one shot:
- JWT login as the seed user
- USD → EUR cross-currency transfer with live FX rate applied
- Second cross-currency transfer (different amount, same route)
- Idempotency replay — same key returns the original transfer, no double-spend
- Polls up to 10 s for the async worker to complete both transfers
- Prints final status of each transfer

After running `make demo`, open `https://db.fund-transfer.local` to inspect the `transfers`, `ledger_entries`, `accounts`, and `audit_logs` tables live.

---

## API Documentation

The landing page at **`https://api.fund-transfer.local`** gives a live overview of the project: quality metrics (test count, mutation score, PHPStan level, k6 VUs), security and architecture highlights, all endpoints, make command reference, and links to every report and tool.

Interactive Swagger UI is available at **`https://api.fund-transfer.local/api/docs`** — no authentication required to browse the spec.

- **OpenAPI JSON**: `https://api.fund-transfer.local/api/docs.json`
- **OpenAPI YAML**: `https://api.fund-transfer.local/api/docs.yaml`

The UI includes the Bearer token flow: click **Authorize**, enter `Bearer <token>` and all subsequent requests will include the JWT.

---

## API Reference

All endpoints are under `/api/v1/`. Authentication uses `Authorization: Bearer <jwt>`.

### Auth

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/api/v1/auth/register` | Register + get JWT |
| `POST` | `/api/v1/auth/login` | Login + get JWT |
| `POST` | `/api/v1/auth/refresh` | Refresh JWT |
| `POST` | `/api/v1/auth/logout` | Revoke refresh token |

### Accounts

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/v1/accounts` | List my accounts |
| `POST` | `/api/v1/accounts` | Open new account |
| `GET` | `/api/v1/accounts/{id}` | Get account details |
| `PATCH` | `/api/v1/accounts/{id}` | Update account name |
| `DELETE` | `/api/v1/accounts/{id}` | Close account (zero balance required) |

### Transfers

| Method | Path | Description |
|--------|------|-------------|
| `POST` | `/api/v1/transfers` | Initiate transfer (requires `Idempotency-Key` header) |
| `GET` | `/api/v1/transfers/{id}` | Get transfer status |

### FX Rates

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/v1/fx-rates/{from}/{to}` | Latest rate for a currency pair (e.g. `USD/EUR`) |

### User

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/v1/me` | Lightweight current-user profile (MeController) |
| `GET` | `/api/v1/users/me` | Full user profile |
| `GET` | `/api/v1/users/me/export` | GDPR data export (all data + transfer history) |
| `DELETE` | `/api/v1/users/me` | Delete account (GDPR right to erasure) |

### Transfer request example

```bash
curl -k -X POST https://api.fund-transfer.local/api/v1/transfers \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{
    "from_account_id": "<uuid>",
    "to_account_id": "<uuid>",
    "amount": "100.00",
    "currency": "USD"
  }'
```

Response `202 Accepted`:
```json
{
  "data": {
    "id": "<transfer-uuid>",
    "status": "pending",
    "amount": "100.00",
    "currency": "USD"
  }
}
```

---

## Testing

### Test suite overview

**210 tests · 369 assertions** across unit and integration layers. All tests run against real PostgreSQL and Redis — no mocks for infrastructure.

| Layer | What it covers |
|---|---|
| **Unit** | Domain entities, value objects, application handlers in isolation |
| **Integration — API** | Full HTTP request/response cycle: auth, accounts, transfers, FX rates, user |
| **Integration — Security** | KYC gating enforcement, OWASP top-10 checks (injection, malformed input, error shape) |
| **Integration — Concurrency** | Sequential double-spend proof via pessimistic locking; idempotency deduplication |
| **Architecture** | Deptrac enforces strict layer boundaries — controllers cannot import domain directly, etc. |
| **Mutation** | Pest `--mutate` validates test quality: 361 mutations across Domain + Application layers |
| **Load** | k6 scenario — 50 concurrent VUs, pessimistic lock contention, idempotency under load |

### Commands

```bash
make test              # Full suite — unit + integration (real DB + Redis)
make test-unit         # Unit tests only (fast, no external services needed)
make test-integration  # Integration tests only
make coverage          # HTML coverage report → docs/reports/coverage/index.html
make mutation          # Pest mutation testing — 361 mutations, Domain + Application layers
make deptrac           # Architecture layer enforcement — zero violations permitted
make load-test         # k6 load test (run make prod && make seed first)
```

To run a single test class:
```bash
docker compose exec app ./vendor/bin/pest --filter TransferControllerTest
```

### Coverage report

After running `make coverage`, open `https://api.fund-transfer.local/reports/coverage` in the browser to view the full HTML report.

### Mutation testing

Mutation testing goes beyond coverage — it introduces deliberate code changes (flip a comparison, remove a return) and verifies that the test suite catches each one. Run:

```bash
make mutation
```

Pest tests 361 mutations across `src/Domain` and `src/Application`. A surviving mutant means a test that asserts presence but not correctness — this is what mutation testing finds that line coverage misses.

### Architecture enforcement

```bash
make deptrac
```

Deptrac enforces that:
- Controllers only import Application and Domain
- Application only imports Domain
- Domain has zero framework dependencies
- Infrastructure implements Domain interfaces — never the reverse

Zero violations are permitted. The CI gate (`make ci`) fails if any layer rule is broken.

---

## Performance

### FrankenPHP worker mode

FrankenPHP runs in **worker mode** — the Symfony application boots once per PHP thread and then handles requests in a loop, eliminating the per-request bootstrap cost (config parsing, DI container build, autoloader scan). This is the primary performance advantage over traditional php-fpm.

Worker mode is enabled in both dev and prod via:
- `worker /app/public/index.php` in the Caddyfile
- `APP_RUNTIME=Runtime\FrankenPhpSymfony\Runtime` in the environment
- `runtime/frankenphp-symfony` handles per-request kernel reset to prevent state leakage between requests

> In dev, code changes require `docker compose restart app` to reload the workers (the app is cached in memory). In prod this is irrelevant.

### Dev vs Production mode

By default the stack runs in `APP_ENV=dev`. To benchmark at production speed:

```bash
make prod   # APP_ENV=prod + compiled DI container + JIT + no debug overhead
```

Key differences in prod mode:
- **Compiled DI container** — no YAML parsing on boot
- **JIT enabled** (`opcache.jit=tracing`, 64 MB buffer) — PHP 8 tracing JIT
- **OPcache validate_timestamps=0** — no filesystem stat calls
- **Monolog fingers_crossed** — logs buffered in memory, flushed only on warning+

### Load testing

```bash
make prod        # Switch to production mode first
make seed        # Seed KYC-verified users, funded accounts, and FX rate rows
make load-test   # Run k6 — 50 VU ramp, 100-second scenario
```

#### What the scenario proves

The scenario is **deliberately adversarial**: all 50 VUs hit the **same source → destination account pair** for the entire run. This is the worst case for a pessimistic locking implementation — every writer competes for the same two row locks simultaneously.

The goal is not to maximise throughput (spreading load across many pairs would be trivially fast). The goal is to prove that the system maintains **correctness under maximum lock contention**:

- No double-spend — every accepted transfer debits the source exactly once
- No data race — account balances remain consistent across all concurrent writers
- No server errors — every response is either a successful 202 or a handled 4xx (e.g. insufficient balance), never a 500

FX rates are pre-seeded into the DB so cache misses fall back to the DB row rather than blocking on the external Frankfurter API under load.

#### Thresholds

| Threshold | Target | Rationale |
|---|---|---|
| `p(95) < 800ms` | 95th percentile response time | Headroom for slower hardware |
| `checks rate > 99%` | Assertion pass rate | Validates correct status codes, not just HTTP success |
| `transfers_accepted > 100` | Minimum accepted writes | Proves the lock releases and progress is made |

#### Reference results (local Docker, MacBook M-series, prod mode)

> **Note:** Absolute latency numbers will vary with your hardware. What matters is that all thresholds pass — the correctness guarantees (no double-spend, no 5xx, >99% checks) hold regardless of machine speed.

| Metric | Value |
|---|---|
| VUs at peak | 50 (all on same account pair) |
| Total requests | 17,200+ |
| Throughput | ~171 req/s |
| p(50) latency | 11ms |
| p(95) latency | **31ms** (threshold: 800ms) |
| p(99) latency | 34ms |
| Max latency | 135ms |
| Transfers accepted | 4,100+ |
| Transfers rejected (4xx) | ~1,500 (balance depleted — correct behaviour) |
| HTTP failures (5xx) | **0** |
| Checks pass rate | **100%** |

Results are written to `tests/load/results.json` after each run. Run `make load-test` on your own machine — the thresholds are what pass or fail CI, not the raw numbers.

---

## Code Quality

```bash
make analyse    # PHPStan level 9 — maximum strictness
make cs-check   # PHP-CS-Fixer dry-run
make cs-fix     # PHP-CS-Fixer auto-fix
make rector     # Rector dry-run (PHP 8.4 modernisation checks)
make deptrac    # Architecture layer enforcement
make audit      # composer audit — known CVE scan
make lint       # All of the above in one shot
make ci         # lint + full test suite (what CI runs)
```

All gates must pass before merging. `make ci` combines `make lint` and `make test` into a single command matching what a CI pipeline would run.

---

## What I Would Add With More Time

- **Rate limiting** — per-user transfer rate limits via Redis (token bucket)
- **Webhook notifications** — POST to a user-configured URL when transfer status changes
- **Admin endpoints** — manually approve/reject KYC, freeze accounts
- **True concurrency tests** — parallel process forking (`pcntl_fork`) to fire simultaneous DB transactions in a single PHPUnit test, exercising pessimistic locks under real concurrent load (the k6 scenario covers this at the HTTP layer)
- **JWT blocklist** — invalidate tokens on user deletion (currently closed users can still auth until JWT expires)

---

## Time Spent

**~20 hours** across architecture, implementation, testing, and iterative hardening (cross-currency fix, idempotency scoping, input validation, security review).

---

## AI Tools Used

**Claude (Anthropic)** was used throughout this project via Claude Code (CLI). Usage pattern:

- Architecture and plan review before implementation
- Parallel subagent execution for independent tasks (domain layer, FX service, auth, transfer system, compliance)
- Code quality and security review after each plan
- Bug identification and fix implementation (cross-currency `Money::convertTo`, idempotency key scoping, self-transfer validation, test key name mismatch)

All generated code was reviewed, understood, and owned. The architecture decisions, DDD structure, pessimistic locking strategy, and security posture reflect deliberate engineering choices — not copy-paste.
