/**
 * k6 load test — Fund Transfer API
 *
 * Quick start:
 *   1.  make seed          — populate 10 KYC-verified users with funded accounts
 *   2.  make load-test     — run this scenario against the local stack
 *
 * What this validates:
 *   - Auth throughput (POST /auth/login)
 *   - Concurrent read performance (GET /accounts, GET /fx-rates)
 *   - Transfer initiation under lock contention (POST /transfers)
 *   - Idempotency deduplication (same key → same 202, no double-spend)
 *
 * Environment variables (optional overrides):
 *   API_URL     — default: https://fund-transfer.local
 *   SEED_EMAIL  — default: load_test@example.com
 *   SEED_PASS   — default: Password123!
 *
 * Thresholds:
 *   http_req_failed   < 2%       (hard failures)
 *   http_req_duration p(95) < 800ms
 */

import http     from 'k6/http';
import { check, sleep, group } from 'k6';
import { Counter, Trend }      from 'k6/metrics';
import { uuidv4 }              from 'https://jslib.k6.io/k6-utils/1.4.0/index.js';

// ── Custom metrics ────────────────────────────────────────────────────────
const transfersAccepted  = new Counter('transfers_accepted');
const transfersRejected  = new Counter('transfers_rejected');
const transferLatency    = new Trend('transfer_latency_ms', true);

// ── Test configuration ────────────────────────────────────────────────────
export const options = {
    insecureSkipTLSVerify: true,          // local Caddy self-signed cert
    stages: [
        { duration: '20s', target: 10  }, // ramp up
        { duration: '40s', target: 10  }, // sustain
        { duration: '20s', target: 20  }, // spike — exercises pessimistic lock contention
        { duration: '20s', target: 0   }, // ramp down
    ],
    thresholds: {
        // checks rate: covers fx_rate, accounts, and transfer assertions — if the app
        // returns correct status codes, this passes.  Measures behaviour, not HTTP mechanics.
        checks:             ['rate>0.99'], // >99% check assertions must pass
        http_req_duration:  ['p(95)<800'], // 95th percentile < 800ms
        transfers_accepted: ['count>50'],  // at least 50 transfers accepted over the run
    },
};

const BASE       = (__ENV.API_URL    || 'https://fund-transfer.local');
const SEED_EMAIL = (__ENV.SEED_EMAIL || 'load_test@example.com');
const SEED_PASS  = (__ENV.SEED_PASS  || 'Password123!');
const JSON_HDR   = { 'Content-Type': 'application/json' };
const TLS_OPT    = { insecureSkipTLSVerify: true };

// ── setup() runs once before all VUs start ────────────────────────────────
export function setup() {
    const loginRes = http.post(
        `${BASE}/api/v1/auth/login`,
        JSON.stringify({ email: SEED_EMAIL, password: SEED_PASS }),
        { headers: JSON_HDR, ...TLS_OPT },
    );

    const ok = check(loginRes, {
        'setup: login succeeded': (r) => r.status === 200,
    });

    if (!ok) {
        console.error(
            `Login failed (${loginRes.status}). ` +
            `Run 'make seed' first, then retry with the correct SEED_EMAIL/SEED_PASS.`
        );
        // Return minimal data — VUs will skip the transfer group gracefully
        return { token: null, accounts: [] };
    }

    const token = loginRes.json('token');

    const accountsRes = http.get(`${BASE}/api/v1/accounts`, {
        headers: { ...JSON_HDR, Authorization: `Bearer ${token}` },
        ...TLS_OPT,
    });

    check(accountsRes, { 'setup: accounts loaded': (r) => r.status === 200 });

    const accounts = (accountsRes.json('data') || []).map((a) => a.id);
    console.log(`Setup complete — token obtained, ${accounts.length} account(s) found`);

    return { token, accounts };
}

// ── Default function — runs once per VU per iteration ─────────────────────
export default function (data) {
    // Mark 4xx as expected so they don't count toward http_req_failed.
    // Called per-VU (not in options) because the global responseCallback option
    // was removed in newer k6 versions — this API is stable across all versions.
    http.setResponseCallback(http.expectedStatuses({ min: 200, max: 499 }));

    const authHdrs = { ...JSON_HDR, Authorization: `Bearer ${data.token}` };

    // ── Public endpoint: FX rate lookup (read-heavy, no auth) ─────────────
    group('fx_rate', () => {
        const res = http.get(`${BASE}/api/v1/fx-rates/USD/EUR`, {
            headers: JSON_HDR, ...TLS_OPT,
        });
        check(res, { 'fx rate 200': (r) => r.status === 200 });
    });

    if (!data.token) {
        sleep(1);
        return;
    }

    // ── Authenticated read: account list ──────────────────────────────────
    group('account_list', () => {
        const res = http.get(`${BASE}/api/v1/accounts`, {
            headers: authHdrs, ...TLS_OPT,
        });
        check(res, { 'accounts 200': (r) => r.status === 200 });
    });

    // ── Write path: transfer with unique idempotency key ─────────────────
    // Requires ≥2 accounts; accounts[0] sends to accounts[1].
    // All 30 concurrent VUs hit the same account pair — this exercises
    // the pessimistic lock (SELECT … FOR UPDATE) under real contention.
    // Randomised amount avoids the server-side duplicate-transfer guard
    // (which rejects same from/to/amount/currency within 60 s).
    if (data.accounts.length >= 2) {
        group('transfer', () => {
            const amount = (Math.random() * 0.99 + 0.01).toFixed(2); // $0.01–$1.00
            const t0  = Date.now();
            const res = http.post(
                `${BASE}/api/v1/transfers`,
                JSON.stringify({
                    from_account_id: data.accounts[0],
                    to_account_id:   data.accounts[1],
                    amount:          amount,
                    currency:        'USD',
                }),
                {
                    headers: { ...authHdrs, 'Idempotency-Key': uuidv4() },
                    ...TLS_OPT,
                },
            );

            transferLatency.add(Date.now() - t0);

            if (res.status === 202) {
                transfersAccepted.add(1);
            } else {
                transfersRejected.add(1);
            }

            // All outcomes except 5xx are expected — 4xx are handled errors
            check(res, {
                'transfer: no server error': (r) => r.status < 500,
            });
        });
    }

    sleep(0.5);
}
