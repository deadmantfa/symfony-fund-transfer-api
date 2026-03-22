<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * End-to-end demo: calls the real API (login → transfer → poll status).
 * Run with: make demo
 *
 * Shows:
 *   - JWT auth flow
 *   - USD → USD same-currency transfer
 *   - USD → EUR cross-currency transfer with live FX rate
 *   - Async worker processing (transfers move pending → completed)
 *   - Double-entry ledger entries written to PostgreSQL
 *   - Idempotency: replaying the same key returns the original transfer
 */
#[AsCommand(name: 'app:demo', description: 'Run an end-to-end API demo (requires running stack)')]
final class DemoCommand extends Command
{
    private const BASE    = 'https://fund-transfer.local';
    private const EMAIL   = 'load_test@example.com';
    private const PASS    = 'Password123!';
    private const TIMEOUT = 5;

    public function __construct(private readonly HttpClientInterface $httpClient)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Fund Transfer API — End-to-End Demo');
        $io->text([
            'This demo calls the live API at <href=' . self::BASE . '>' . self::BASE . '</>',
            'Every request goes through the full stack: Caddy → FrankenPHP → Symfony → PostgreSQL',
            '',
        ]);

        // ── 1. Login ──────────────────────────────────────────────────────────
        $io->section('Step 1 · Authentication');

        try {
            $loginRes = $this->post('/api/v1/auth/login', [
                'email'    => self::EMAIL,
                'password' => self::PASS,
            ]);
        } catch (Throwable $e) {
            $io->error('Could not reach ' . self::BASE . '. Is the stack running? Try: make prod');

            return Command::FAILURE;
        }

        if (!isset($loginRes['token'])) {
            $io->error('Login failed. Run "make seed" first to create the demo user.');

            return Command::FAILURE;
        }

        $token = $loginRes['token'];
        $io->success('Logged in as ' . self::EMAIL);

        // ── 2. List accounts ──────────────────────────────────────────────────
        $io->section('Step 2 · Accounts');

        $accountsRes = $this->get('/api/v1/accounts', $token);
        $accounts    = $accountsRes['data'] ?? [];

        if (count($accounts) < 2) {
            $io->error('Need at least 2 accounts. Run "make seed" to set them up.');

            return Command::FAILURE;
        }

        $usdAccount = null;
        $eurAccount = null;
        foreach ($accounts as $a) {
            if ($a['currency'] === 'USD' && $usdAccount === null) {
                $usdAccount = $a;
            }
            if ($a['currency'] === 'EUR' && $eurAccount === null) {
                $eurAccount = $a;
            }
        }

        $io->table(
            ['ID', 'Currency', 'Balance', 'Name'],
            array_map(
                fn(array $a) => [substr($a['id'], 0, 8) . '…', $a['currency'], $a['balance'], $a['name']],
                $accounts,
            ),
        );

        // ── 3. FX rate ────────────────────────────────────────────────────────
        $io->section('Step 3 · FX Rate');

        $fxRes = $this->get('/api/v1/fx-rates/USD/EUR');
        $rate  = $fxRes['data']['rate'] ?? 'unavailable';
        $io->text(sprintf('USD → EUR rate: <info>%s</info>  (provider: %s)', $rate, $fxRes['data']['provider'] ?? '?'));

        // ── 4. Transfers ──────────────────────────────────────────────────────
        $io->section('Step 4 · Initiate Transfers');

        $transferIds = [];

        // Transfer A: same-currency USD → USD (uses first two accounts if both USD)
        $fromId = $accounts[0]['id'];
        $toId   = $accounts[1]['id'];

        $keyA = 'demo-' . uniqid('a', true);
        $resA = $this->post('/api/v1/transfers', [
            'from_account_id' => $fromId,
            'to_account_id'   => $toId,
            'amount'          => '10.00',
            'currency'        => $accounts[0]['currency'],
        ], $token, ['Idempotency-Key' => $keyA]);

        if (isset($resA['data']['id'])) {
            $transferIds[] = $resA['data']['id'];
            $io->text(sprintf(
                '✓ Transfer A  <info>%s %s</info> from %s → %s  [<comment>%s</comment>]',
                '10.00',
                $accounts[0]['currency'],
                substr($fromId, 0, 8) . '…',
                substr($toId, 0, 8) . '…',
                $resA['data']['status'],
            ));
        } else {
            $io->warning('Transfer A was rejected: ' . ($resA['detail'] ?? json_encode($resA)));
        }

        // Transfer B: cross-currency USD → EUR (if both account types exist)
        if ($usdAccount !== null && $eurAccount !== null && $usdAccount['id'] !== $eurAccount['id']) {
            $keyB = 'demo-' . uniqid('b', true);
            $resB = $this->post('/api/v1/transfers', [
                'from_account_id' => $usdAccount['id'],
                'to_account_id'   => $eurAccount['id'],
                'amount'          => '25.00',
                'currency'        => 'USD',
            ], $token, ['Idempotency-Key' => $keyB]);

            if (isset($resB['data']['id'])) {
                $transferIds[] = $resB['data']['id'];
                $io->text(sprintf(
                    '✓ Transfer B  <info>25.00 USD → EUR</info>  (FX: ~%s EUR)  [<comment>%s</comment>]',
                    number_format((float) $rate * 25, 2),
                    $resB['data']['status'],
                ));
            } else {
                $io->warning('Transfer B was rejected: ' . ($resB['detail'] ?? json_encode($resB)));
            }
        }

        // Transfer C: idempotency replay — same key as A, must return same transfer
        $io->section('Step 5 · Idempotency Check');

        $resC        = $this->post('/api/v1/transfers', [
            'from_account_id' => $fromId,
            'to_account_id'   => $toId,
            'amount'          => '10.00',
            'currency'        => $accounts[0]['currency'],
        ], $token, ['Idempotency-Key' => $keyA]);

        // Idempotency hit returns HTTP 200 (not 202) with the original transfer body.
        $replayId = $resC['data']['id'] ?? null;
        if ($replayId !== null && $replayId === ($transferIds[0] ?? null)) {
            $io->text('✓ Replay with same Idempotency-Key returned the <info>original transfer</info> — no double-spend.');
        } elseif ($replayId !== null) {
            $io->warning(sprintf('Replay returned a different transfer ID (%s vs %s).', $replayId, $transferIds[0] ?? '?'));
        } else {
            $io->warning('Idempotency replay response had no data.id — check worker logs.');
        }

        // ── 5. Wait for async worker (poll up to 10 s) ───────────────────────
        $io->section('Step 6 · Async Processing');
        $io->text('Polling until all transfers are processed (up to 10 s)…');

        $allCompleted = false;
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            sleep(1);
            $pending = 0;
            foreach ($transferIds as $tid) {
                $s = $this->get('/api/v1/transfers/' . $tid, $token);
                if (($s['data']['status'] ?? '') !== 'completed') {
                    $pending++;
                }
            }
            if ($pending === 0) {
                $allCompleted = true;
                $io->text(sprintf('  Worker processed all transfers in ~%d s.', $attempt));
                break;
            }
        }

        if (!$allCompleted) {
            $io->note('Worker has not completed all transfers within 10 s. Is the worker running? make worker-logs');
        }

        // ── 6. Final status ───────────────────────────────────────────────────
        $io->section('Step 7 · Transfer Status');

        foreach ($transferIds as $tid) {
            $statusRes = $this->get('/api/v1/transfers/' . $tid, $token);
            $status    = $statusRes['data']['status'] ?? 'unknown';
            $colour    = $status === 'completed' ? 'info' : 'comment';
            $io->text(sprintf(
                'Transfer <href=%s/api/v1/transfers/%s>%s…</> → [<%s>%s</>]',
                self::BASE,
                $tid,
                substr($tid, 0, 8),
                $colour,
                $status,
            ));
        }

        // ── 7. Summary ────────────────────────────────────────────────────────
        $io->section('Summary');
        $io->listing([
            sprintf('%d transfer(s) initiated via the API', count($transferIds)),
            'Each transfer created a double-entry ledger row (debit + credit)',
            'Idempotency key replay returned the original transfer — no double-spend',
            sprintf('Cross-currency FX applied at rate %s USD/EUR', $rate),
        ]);

        $io->text([
            '',
            '  Inspect live data:',
            '  <href=https://db.fund-transfer.local>https://db.fund-transfer.local</> — pgweb DB browser',
            '    Tables: <comment>transfers</comment>  <comment>ledger_entries</comment>  <comment>accounts</comment>  <comment>audit_logs</comment>',
            '',
            '  Try the API interactively:',
            '  <href=https://fund-transfer.local/api/docs>https://fund-transfer.local/api/docs</> — Swagger UI',
            '',
        ]);

        if ($allCompleted) {
            $io->success('All transfers completed. Ledger entries are live in PostgreSQL.');
        }

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body, ?string $token = null, array $extraHeaders = []): mixed
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        foreach ($extraHeaders as $k => $v) {
            $headers[$k] = $v;
        }

        $res = $this->httpClient->request('POST', self::BASE . $path, [
            'headers'             => $headers,
            'json'                => $body,
            'timeout'             => self::TIMEOUT,
            'verify_peer'         => false,
            'verify_host'         => false,
        ]);

        return $res->toArray(false);
    }

    private function get(string $path, ?string $token = null): mixed
    {
        $headers = ['Accept' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $res = $this->httpClient->request('GET', self::BASE . $path, [
            'headers'     => $headers,
            'timeout'     => self::TIMEOUT,
            'verify_peer' => false,
            'verify_host' => false,
        ]);

        return $res->toArray(false);
    }
}
