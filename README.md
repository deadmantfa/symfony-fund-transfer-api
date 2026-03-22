# Fund Transfer API

A secure, production-ready Symfony API for transferring funds between accounts. Built as a homework assignment to demonstrate scalable financial system design.

---

## Tech Stack

- **PHP 8.4** · **Symfony 7** · **FrankenPHP** (runtime)
- **PostgreSQL 16** — primary persistence (chosen over MySQL for superior `SELECT … FOR UPDATE` pessimistic locking semantics, native UUID type, and stronger ACID guarantees — directly relevant to financial integrity)
- **Redis 7** — idempotency key caching, Messenger transport
- **Doctrine DBAL** (no ORM — raw SQL with typed domain objects)
- **Symfony Messenger** — async transfer processing via worker
- **brick/math** — arbitrary-precision decimal arithmetic for money
- **LexikJWTAuthenticationBundle** — RS256 JWT, stateless auth
- **libsodium** — field-level encryption (PII at rest)

---

## Architecture

The project follows a strict layered DDD structure:

```
src/
  Controller/Api/V1/   # Thin HTTP layer — validates input, delegates, returns JSON
  Application/         # Use-case handlers (InitiateTransfer, ProcessTransfer, etc.)
  Domain/              # Pure business logic: Account, Transfer, Money, User
  Infrastructure/      # Doctrine repositories, FX rate provider, encryption
  EventListener/       # RFC 9457 Problem Details normalizer + correlation ID propagation
  Monolog/             # CorrelationIdProcessor — injects X-Request-ID into every log record
  Security/            # JWT user provider
```

**Key design decisions:**

- **Pessimistic locking** — accounts are locked with `SELECT ... FOR UPDATE` in ascending UUID order (deadlock prevention) before any debit/credit
- **Double-entry ledger** — every transfer creates two `LedgerEntry` rows (debit + credit)
- **Idempotency** — Redis-backed, scoped per user (`userId.rawKey`), with DB-level duplicate detection as a secondary guard
- **Async processing** — transfers are created as `pending` and dispatched to a Symfony Messenger worker via Redis; Messenger retries failed jobs with exponential back-off
- **Cross-currency** — FX rates fetched from Frankfurter API, cached in Redis + DB; `Money::convertTo()` handles currency conversion without precision loss
- **KYC gating** — users must have `kyc_status = verified` + verified email before initiating transfers
- **GDPR export** — `GET /api/v1/me/export` returns all user data + transfer history as JSON
- **Structured logging** — Monolog with JSON formatter to stdout (Docker-friendly); dedicated `transfer` and `security` channels; every log record carries an `X-Request-ID` correlation ID for end-to-end tracing

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

### 2. Add the local domain to `/etc/hosts`

All local services are served under `fund-transfer.local` with HTTPS. Add all three subdomains in one line:

```bash
make hosts   # prints the exact line to add, then run:
sudo sh -c 'echo "127.0.0.1 fund-transfer.local db.fund-transfer.local mail.fund-transfer.local" >> /etc/hosts'
```

| URL | Service |
|-----|---------|
| `https://fund-transfer.local` | API + Swagger UI |
| `https://db.fund-transfer.local` | pgweb database browser (auto-connects) |
| `https://mail.fund-transfer.local` | Mailpit SMTP catcher |

### 3. Start services

```bash
make up
```

This builds the image and starts five containers in the background:

| Container | Role |
|-----------|------|
| `app` | FrankenPHP + Caddy — API at `https://fund-transfer.local` |
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

### 6. (Optional) Trust the local TLS certificate

Caddy auto-generates a certificate via its own local CA. Installing it removes the browser warning:

```bash
make trust-ca   # exports the cert to /tmp/caddy-local-ca.crt

# macOS
sudo security add-trusted-cert -d -r trustRoot \
  -k /Library/Keychains/System.keychain \
  /tmp/caddy-local-ca.crt
# Then restart Chrome and open https://fund-transfer.local
```

> **Skipping this step is fine** — click **Advanced → Proceed to fund-transfer.local (unsafe)** to continue.

### 7. Seed test data and run the demo

```bash
make seed   # 10 KYC-verified users, 20 funded accounts, FX rate rows
make demo   # end-to-end demo: login → transfer → worker processes → ledger entries
```

`make demo` calls the live API and shows the full flow in one shot:
- JWT login
- USD → USD same-currency transfer
- USD → EUR cross-currency transfer (FX rate applied)
- Idempotency replay (same key → same transfer, no double-spend)
- Waits 3 s for the async worker, then shows final status

After running `make demo`, open `https://db.fund-transfer.local` to inspect the `transfers`, `ledger_entries`, `accounts`, and `audit_logs` tables live.

---

## API Documentation

Interactive Swagger UI is available at **`https://fund-transfer.local/api/docs`** — no authentication required to browse the spec.

- **OpenAPI JSON**: `https://fund-transfer.local/api/docs.json`
- **OpenAPI YAML**: `https://fund-transfer.local/api/docs.yaml`

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
| `GET` | `/api/v1/me` | Current user profile |
| `GET` | `/api/v1/me/export` | GDPR data export |
| `DELETE` | `/api/v1/me` | Delete account (GDPR) |

### Transfer request example

```bash
curl -X POST https://fund-transfer.local/api/v1/transfers \
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

## Running Tests

```bash
make test              # Full suite — unit + integration (real DB + Redis)
make test-unit         # Unit tests only (fast, no external services needed)
make test-integration  # Integration tests only
make coverage          # Generate HTML coverage report → /reports/coverage
make mutation          # Pest mutation testing — Domain + Application layer (target 80%+)
```

To run a single test class:
```bash
docker compose exec app ./vendor/bin/pest --filter TransferControllerTest
```

**Test breakdown:** 210 tests, 369 assertions — unit + integration layers.

Integration tests hit a real PostgreSQL + Redis instance. Each test class truncates all tables in `setUp()` for isolation.

## Performance

### Dev vs Production mode

By default the stack runs in `APP_ENV=dev` (debug toolbar, per-request container rebuild, timestamp-validated OPcache). This is intentionally slower — it gives you stack traces and the profiler.

To benchmark at production speed:

```bash
make prod   # APP_ENV=prod + JIT-enabled php.ini + compiled DI container
```

Key differences in prod mode:
- **JIT enabled** (`opcache.jit=tracing`, 64 MB buffer) — PHP 8 tracing JIT for maximum throughput
- **OPcache validate_timestamps=0** — no file-system stat calls per request
- **Compiled DI container** — no YAML parsing on boot
- **Monolog fingers_crossed** — logs buffered in memory, only flushed on warning+

### Load testing

The project ships a [k6](https://k6.io) load test scenario that exercises concurrent transfer initiation, pessimistic lock contention, and idempotency deduplication.

```bash
make prod        # Switch to production mode first — 32 PHP threads, JIT, no debug overhead
make seed        # Seed KYC-verified users, funded accounts, and FX rate rows
make load-test   # Run k6 (0→10→20 VU ramp, 100-second scenario)
```

Thresholds: `p(95) < 800ms`, `checks pass rate > 99%`, at least 50 transfers accepted.

The scenario ramps to 20 concurrent VUs, all hitting the same account pair to exercise the pessimistic `SELECT … FOR UPDATE` lock under real contention. FX rates are pre-seeded into the DB so the DB fallback serves cache misses without blocking on the external Frankfurter API.

> **Dev vs prod**: Running `make load-test` without `make prod` first causes FrankenPHP to exhaust its default thread count (≈ 2×CPU) at the 20 VU spike, dropping connections. Prod mode explicitly allocates 32 PHP threads and removes per-request debug overhead.

Results are written to `tests/load/results.json` after each run.

## Static Analysis & Code Style

```bash
make analyse    # PHPStan level 9
make cs-check   # PHP-CS-Fixer dry-run
make cs-fix     # PHP-CS-Fixer auto-fix
make rector     # Rector dry-run (modernisation checks)
make deptrac    # Deptrac architecture layer enforcement
make audit      # composer audit (known CVEs)
make lint       # Run all of the above in one shot
```

---

## What I Would Add With More Time

- **Rate limiting** — per-user transfer rate limits via Redis (token bucket)
- **Webhook notifications** — POST to a user-configured URL when transfer status changes
- **Admin endpoints** — manually approve/reject KYC, freeze accounts
- **Mutation testing** — Infection PHP to validate test quality beyond coverage
- **True concurrency tests** — parallel process forking (e.g. `pcntl_fork`) to fire simultaneous DB transactions in a single PHPUnit test, exercising pessimistic locks under real concurrent load (the k6 scenario covers this at the HTTP layer)
- **JWT blocklist** — invalidate tokens on user deletion (currently closed users can still auth until JWT expires)

---

## Time Spent

**~20 hours** across architecture, implementation, testing, and iterative hardening (cross-currency fix, idempotency scoping, input validation, security review).

---

## AI Tools Used

**Claude (Anthropic)** was used throughout this project via Claude Code (CLI). Usage pattern:

- Architecture and plan review before implementation
- Parallel subagent execution for independent tasks (domain layer, FX service, auth, transfer system, compliance)
- Code quality and security review ("brutal code review") after each plan
- Bug identification and fix implementation (cross-currency `Money::convertTo`, idempotency key scoping, self-transfer validation)

All generated code was reviewed, understood, and owned. The architecture decisions, DDD structure, pessimistic locking strategy, and security posture reflect deliberate engineering choices — not copy-paste.
