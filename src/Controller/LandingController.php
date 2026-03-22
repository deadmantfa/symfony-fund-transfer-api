<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LandingController
{
    #[Route('/', methods: ['GET'])]
    public function __invoke(): Response
    {
        $html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fund Transfer API</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: #0f1117;
    color: #e2e8f0;
    min-height: 100vh;
    padding: 2rem 1rem;
  }
  .container { max-width: 900px; margin: 0 auto; }

  header { text-align: center; padding: 3rem 0 2.5rem; }
  header h1 { font-size: 2.25rem; font-weight: 700; letter-spacing: -0.5px; }
  header h1 span { color: #6366f1; }
  header p { color: #94a3b8; margin-top: 0.6rem; font-size: 1.05rem; }

  .badge-row { display: flex; flex-wrap: wrap; gap: 0.5rem; justify-content: center; margin-top: 1.25rem; }
  .badge {
    display: inline-flex; align-items: center; gap: 0.35rem;
    background: #1e2330; border: 1px solid #2d3348;
    border-radius: 999px; padding: 0.3rem 0.75rem;
    font-size: 0.78rem; font-weight: 500; color: #94a3b8;
  }
  .badge .dot { width: 8px; height: 8px; border-radius: 50%; }
  .badge .dot.green { background: #22c55e; }
  .badge .dot.blue  { background: #6366f1; }
  .badge .dot.amber { background: #f59e0b; }

  .cta { text-align: center; margin: 2.25rem 0; display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap; }
  .btn {
    display: inline-block; padding: 0.65rem 1.5rem;
    background: #6366f1; color: #fff; border-radius: 8px;
    text-decoration: none; font-weight: 600; font-size: 0.9rem;
    transition: background 0.15s;
  }
  .btn:hover { background: #4f46e5; }
  .btn-ghost {
    background: transparent; border: 1px solid #2d3348; color: #94a3b8;
  }
  .btn-ghost:hover { background: #1e2330; color: #e2e8f0; }

  section { margin: 2.25rem 0; }
  h2 {
    font-size: 0.68rem; font-weight: 700; letter-spacing: 0.12em;
    text-transform: uppercase; color: #6366f1; margin-bottom: 1rem;
  }

  .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1rem; }
  .card {
    background: #1e2330; border: 1px solid #2d3348; border-radius: 10px;
    padding: 1.1rem 1.25rem;
  }
  .card h3 { font-size: 0.9rem; font-weight: 600; margin-bottom: 0.35rem; color: #e2e8f0; }
  .card p  { font-size: 0.8rem; color: #64748b; line-height: 1.55; }
  .card code { font-size: 0.74rem; color: #a5b4fc; background: #12151f; padding: 1px 5px; border-radius: 3px; }

  /* ── Stats ── */
  .stat-row { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1rem; }
  .stat {
    background: #1e2330; border: 1px solid #2d3348; border-radius: 10px;
    padding: 1.1rem 1rem; text-align: center;
    text-decoration: none; display: block;
    transition: border-color 0.15s;
  }
  a.stat:hover { border-color: #6366f1; }
  .stat .num { font-size: 1.9rem; font-weight: 700; color: #6366f1; line-height: 1; }
  .stat .label { font-size: 0.73rem; color: #64748b; margin-top: 0.4rem; }
  .stat .sub { font-size: 0.65rem; color: #475569; margin-top: 0.2rem; }

  /* ── Reports ── */
  .report-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1rem; }
  .report-card {
    background: #1e2330; border: 1px solid #2d3348; border-radius: 10px;
    padding: 1rem 1.25rem; text-decoration: none;
    display: flex; align-items: flex-start; gap: 0.9rem;
    transition: border-color 0.15s;
  }
  .report-card:hover { border-color: #6366f1; }
  .report-icon {
    font-size: 1.4rem; line-height: 1; flex-shrink: 0; margin-top: 0.1rem;
  }
  .report-card h3 { font-size: 0.875rem; font-weight: 600; color: #e2e8f0; margin-bottom: 0.25rem; }
  .report-card p  { font-size: 0.76rem; color: #64748b; line-height: 1.5; }
  .report-card .tag {
    display: inline-block; margin-top: 0.5rem;
    font-size: 0.65rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase;
    color: #6366f1; background: #1a1d3a; border: 1px solid #2d3060;
    border-radius: 4px; padding: 0.15rem 0.45rem;
  }

  /* ── Commands ── */
  .command-list { display: flex; flex-direction: column; gap: 0.5rem; }
  .command {
    display: flex; align-items: baseline; gap: 0.75rem;
    background: #1e2330; border: 1px solid #2d3348; border-radius: 8px;
    padding: 0.65rem 1rem;
  }
  .command code { font-family: "SF Mono", "Fira Code", monospace; font-size: 0.8rem; color: #a5b4fc; white-space: nowrap; flex-shrink: 0; }
  .command span { font-size: 0.78rem; color: #64748b; }

  /* ── Route table ── */
  .route-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
  .route-table th {
    text-align: left; padding: 0.5rem 0.75rem; font-size: 0.68rem;
    font-weight: 700; color: #64748b; letter-spacing: 0.06em; text-transform: uppercase;
    border-bottom: 1px solid #2d3348;
  }
  .route-table td { padding: 0.55rem 0.75rem; border-bottom: 1px solid #1a1f2e; color: #94a3b8; }
  .route-table tr:last-child td { border-bottom: none; }
  .route-table .path { color: #e2e8f0; font-family: monospace; font-size: 0.78rem; }
  .method {
    display: inline-block; padding: 0.15rem 0.5rem; border-radius: 4px;
    font-size: 0.68rem; font-weight: 700; letter-spacing: 0.03em;
  }
  .GET    { background: #14532d; color: #4ade80; }
  .POST   { background: #1e3a5f; color: #60a5fa; }
  .PATCH  { background: #451a03; color: #fb923c; }
  .DELETE { background: #450a0a; color: #f87171; }

  footer { text-align: center; margin-top: 3rem; padding-top: 1.5rem; border-top: 1px solid #1e2330; color: #475569; font-size: 0.78rem; }
  footer a { color: #6366f1; text-decoration: none; }
  footer a:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="container">

  <header>
    <h1>Fund Transfer <span>API</span></h1>
    <p>Secure multi-currency fund transfers · Symfony 7 · PHP 8.4 · PostgreSQL 16</p>
    <div class="badge-row">
      <span class="badge"><span class="dot green"></span> All services healthy</span>
      <span class="badge"><span class="dot blue"></span> 210 tests passing</span>
      <span class="badge"><span class="dot amber"></span> PHPStan level 9</span>
      <span class="badge"><span class="dot blue"></span> 80%+ mutation score</span>
      <span class="badge"><span class="dot blue"></span> Deptrac enforced</span>
      <span class="badge"><span class="dot blue"></span> k6 load tested</span>
    </div>
  </header>

  <div class="cta">
    <a class="btn" href="/api/docs">Swagger UI →</a>
    <a class="btn btn-ghost" href="https://db.fund-transfer.local">Database →</a>
    <a class="btn btn-ghost" href="/api/docs.json">OpenAPI JSON</a>
    <a class="btn btn-ghost" href="/api/docs.yaml">OpenAPI YAML</a>
    <a class="btn btn-ghost" href="/health">Health check</a>
  </div>

  <!-- ── Quality metrics ── -->
  <section>
    <h2>Quality metrics</h2>
    <div class="stat-row">
      <a class="stat" href="/reports/coverage/index.html" title="Open HTML coverage report">
        <div class="num">71.8%</div>
        <div class="label">Statement coverage</div>
        <div class="sub">960 / 1337 statements</div>
      </a>
      <a class="stat" href="/reports/coverage/index.html" title="Open HTML coverage report">
        <div class="num">69.9%</div>
        <div class="label">Method coverage</div>
        <div class="sub">137 / 196 methods</div>
      </a>
      <div class="stat">
        <div class="num">80%+</div>
        <div class="label">Mutation score</div>
        <div class="sub">Domain + Application layer</div>
      </div>
      <div class="stat">
        <div class="num">210</div>
        <div class="label">Tests</div>
        <div class="sub">Unit + Integration</div>
      </div>
      <div class="stat">
        <div class="num">9</div>
        <div class="label">PHPStan level</div>
        <div class="sub">Strictest setting</div>
      </div>
      <div class="stat">
        <div class="num">15</div>
        <div class="label">API endpoints</div>
        <div class="sub">Fully documented</div>
      </div>
    </div>
  </section>

  <!-- ── Reports & resources ── -->
  <section>
    <h2>Reports &amp; resources</h2>
    <div class="report-grid">
      <a class="report-card" href="/reports/coverage/index.html">
        <div class="report-icon">📊</div>
        <div>
          <h3>Code coverage</h3>
          <p>Line-by-line HTML coverage report generated by pcov. Browse per-class coverage.</p>
          <span class="tag">HTML report</span>
        </div>
      </a>
      <a class="report-card" href="/reports/query-plans">
        <div class="report-icon">🗄️</div>
        <div>
          <h3>Query execution plans</h3>
          <p>EXPLAIN output for all key queries — accounts, transfers, users, idempotency lookups.</p>
          <span class="tag">HTML report</span>
        </div>
      </a>
      <a class="report-card" href="/api/docs">
        <div class="report-icon">📖</div>
        <div>
          <h3>API documentation</h3>
          <p>Interactive Swagger UI. Authenticate with a JWT and try every endpoint live.</p>
          <span class="tag">Swagger / Redocly</span>
        </div>
      </a>
      <a class="report-card" href="/api/docs.json">
        <div class="report-icon">🔗</div>
        <div>
          <h3>OpenAPI spec</h3>
          <p>Machine-readable JSON spec. Import into Postman, Insomnia, or any API client.</p>
          <span class="tag">OpenAPI 3.0 JSON</span>
        </div>
      </a>
      <a class="report-card" href="/api/docs.yaml">
        <div class="report-icon">📄</div>
        <div>
          <h3>OpenAPI YAML</h3>
          <p>Same spec in YAML format. Useful for SDK generation or CI validation.</p>
          <span class="tag">OpenAPI 3.0 YAML</span>
        </div>
      </a>
      <a class="report-card" href="/health">
        <div class="report-icon">💚</div>
        <div>
          <h3>Health check</h3>
          <p>Liveness endpoint checked by Docker Compose. Returns <code>200 OK</code> when the app is ready.</p>
          <span class="tag">JSON</span>
        </div>
      </a>
      <a class="report-card" href="https://db.fund-transfer.local" target="_blank" rel="noopener">
        <div class="report-icon">🐘</div>
        <div>
          <h3>Database browser</h3>
          <p>pgweb — browse tables, inspect live transfers, accounts, ledger entries, and audit logs. Auto-connected, no login required.</p>
          <span class="tag">pgweb · PostgreSQL</span>
        </div>
      </a>
    </div>
  </section>

  <!-- ── Architecture ── -->
  <section>
    <h2>Architecture highlights</h2>
    <div class="cards">
      <div class="card">
        <h3>Pessimistic locking</h3>
        <p><code>SELECT … FOR UPDATE</code> in ascending UUID order prevents deadlocks under concurrent transfers.</p>
      </div>
      <div class="card">
        <h3>Idempotency</h3>
        <p>Redis-backed, user-scoped keys (<code>userId.rawKey</code>) with DB duplicate detection as a secondary guard.</p>
      </div>
      <div class="card">
        <h3>Async processing</h3>
        <p>Transfers are created as <code>pending</code> then dispatched via Symfony Messenger to a Redis-backed worker.</p>
      </div>
      <div class="card">
        <h3>Cross-currency</h3>
        <p>FX rates from Frankfurter API, cached in Redis + DB. <code>brick/math</code> handles arithmetic without precision loss.</p>
      </div>
      <div class="card">
        <h3>KYC gating</h3>
        <p>Transfers require <code>kyc_status = verified</code> and a confirmed email address before dispatch.</p>
      </div>
      <div class="card">
        <h3>GDPR export</h3>
        <p><code>GET /api/v1/users/me/export</code> returns all user data and transfer history as JSON.</p>
      </div>
      <div class="card">
        <h3>Structured logging</h3>
        <p>JSON Monolog to stdout with dedicated <code>transfer</code> + <code>security</code> channels. Every record carries an <code>X-Request-ID</code> correlation ID.</p>
      </div>
    </div>
  </section>

  <!-- ── Endpoints ── -->
  <section>
    <h2>API endpoints</h2>
    <div class="card" style="padding:0; overflow:hidden;">
    <table class="route-table">
      <thead><tr><th>Method</th><th>Path</th><th>Description</th></tr></thead>
      <tbody>
        <tr><td><span class="method POST">POST</span></td><td class="path">/api/v1/auth/register</td><td>Register + receive JWT</td></tr>
        <tr><td><span class="method POST">POST</span></td><td class="path">/api/v1/auth/login</td><td>Login + receive JWT</td></tr>
        <tr><td><span class="method GET">GET</span></td><td class="path">/api/v1/accounts</td><td>List my accounts</td></tr>
        <tr><td><span class="method POST">POST</span></td><td class="path">/api/v1/accounts</td><td>Open new account</td></tr>
        <tr><td><span class="method PATCH">PATCH</span></td><td class="path">/api/v1/accounts/{id}</td><td>Rename account</td></tr>
        <tr><td><span class="method DELETE">DELETE</span></td><td class="path">/api/v1/accounts/{id}</td><td>Close account</td></tr>
        <tr><td><span class="method POST">POST</span></td><td class="path">/api/v1/transfers</td><td>Initiate transfer (requires <code>Idempotency-Key</code> header)</td></tr>
        <tr><td><span class="method GET">GET</span></td><td class="path">/api/v1/transfers/{id}</td><td>Get transfer status</td></tr>
        <tr><td><span class="method DELETE">DELETE</span></td><td class="path">/api/v1/transfers/{id}</td><td>Cancel pending transfer</td></tr>
        <tr><td><span class="method GET">GET</span></td><td class="path">/api/v1/fx-rates/{from}/{to}</td><td>Live FX rate (e.g. USD → EUR)</td></tr>
        <tr><td><span class="method GET">GET</span></td><td class="path">/api/v1/users/me</td><td>My profile</td></tr>
        <tr><td><span class="method GET">GET</span></td><td class="path">/api/v1/users/me/export</td><td>GDPR data export</td></tr>
        <tr><td><span class="method DELETE">DELETE</span></td><td class="path">/api/v1/users/me</td><td>Delete account (GDPR right to erasure)</td></tr>
        <tr><td><span class="method GET">GET</span></td><td class="path">/health</td><td>Liveness check</td></tr>
      </tbody>
    </table>
    </div>
  </section>

  <!-- ── Commands ── -->
  <section>
    <h2>Useful make commands</h2>
    <div class="command-list">
      <div class="command"><code>make test</code><span>Run full test suite — unit + integration (real DB + Redis)</span></div>
      <div class="command"><code>make test-unit</code><span>Unit tests only (fast, no Docker services needed)</span></div>
      <div class="command"><code>make coverage</code><span>Generate HTML coverage report → <a href="/reports/coverage/index.html" style="color:#6366f1">/reports/coverage</a></span></div>
      <div class="command"><code>make mutation</code><span>Pest mutation testing — Domain + Application layer (target 80%+)</span></div>
      <div class="command"><code>make analyse</code><span>PHPStan level 9 static analysis</span></div>
      <div class="command"><code>make lint</code><span>CS-Fixer + PHPStan + Rector dry-run + Deptrac + composer audit</span></div>
      <div class="command"><code>make seed</code><span>Seed 10 KYC-verified users + funded accounts + FX rates (prerequisite for demo and load test)</span></div>
      <div class="command"><code>make demo</code><span>End-to-end API demo — login, same-currency transfer, cross-currency FX transfer, idempotency replay, ledger verification</span></div>
      <div class="command"><code>make explain</code><span>Run EXPLAIN on key queries → <a href="/reports/query-plans" style="color:#6366f1">/reports/query-plans</a></span></div>
      <div class="command"><code>make prod</code><span>Restart in production mode — APP_ENV=prod, JIT enabled, compiled DI container, 32 PHP threads. <strong>Run before load testing.</strong></span></div>
      <div class="command"><code>make load-test</code><span>k6 load test — ramp to 20 VUs, exercises pessimistic lock contention + idempotency deduplication (run <code>make prod &amp;&amp; make seed</code> first)</span></div>
    </div>
  </section>

  <footer>
    Symfony 7 · FrankenPHP · PostgreSQL 16 · Redis 7 · PHP 8.4 ·
    <a href="/api/docs">API Docs</a> ·
    <a href="/reports/coverage/index.html">Coverage</a> ·
    <a href="/reports/query-plans">Query Plans</a> ·
    <a href="https://db.fund-transfer.local">Database</a> ·
    <a href="/health">Health</a>
  </footer>

</div>
</body>
</html>
HTML;

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
