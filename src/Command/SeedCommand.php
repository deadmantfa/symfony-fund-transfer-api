<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Account\AccountId;
use App\Domain\FxRate\FxRateId;
use App\Domain\User\UserId;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'app:seed', description: 'Seed the database with test data')]
final class SeedCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('users', null, InputOption::VALUE_REQUIRED, 'Number of users', 10)
             ->addOption('accounts', null, InputOption::VALUE_REQUIRED, 'Accounts per user', 2);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io           = new SymfonyStyle($input, $output);
        $usersOpt    = $input->getOption('users');
        $accountsOpt = $input->getOption('accounts');
        $users       = is_numeric($usersOpt) ? (int) $usersOpt : 10;
        $accounts    = is_numeric($accountsOpt) ? (int) $accountsOpt : 2;

        $io->section("Seeding {$users} users, up to {$accounts} accounts/user");

        // Seed FX rates so the DB fallback always has data — prevents thundering-herd
        // timeouts when the Frankfurter API is rate-limited under concurrent load.
        $this->seedFxRates($io);

        // Load test user is an idempotent upsert — runs outside the main transaction
        // so a constraint conflict never poisons the seeding batch.
        $this->seedLoadTestUser($io);

        $this->connection->beginTransaction();

        try {
            // seedAccounts only receives freshly-created user IDs to avoid violating
            // the unique (user_id, currency) index on the load test user's existing accounts.
            $newUserIds = $this->seedUsers($users, $io);
            $this->seedAccounts($accounts, $newUserIds, $io);

            $this->connection->commit();
            $io->success('Seeding complete.');
        } catch (Throwable $e) {
            $this->connection->rollBack();
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Upserts a baseline set of FX rates so the DB fallback in CachingFxRateService
     * always has data even when the external Frankfurter API is rate-limited.
     *
     * Rates are approximate — only used as a fallback when the live API is unavailable.
     */
    private function seedFxRates(SymfonyStyle $io): void
    {
        $io->writeln('Seeding FX rates...');

        $now   = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $pairs = [
            ['USD', 'EUR', '0.9200000000'],
            ['EUR', 'USD', '1.0870000000'],
            ['USD', 'GBP', '0.7900000000'],
            ['GBP', 'USD', '1.2660000000'],
            ['USD', 'CAD', '1.3600000000'],
            ['CAD', 'USD', '0.7350000000'],
            ['USD', 'AUD', '1.5400000000'],
            ['AUD', 'USD', '0.6490000000'],
            ['EUR', 'GBP', '0.8600000000'],
            ['GBP', 'EUR', '1.1630000000'],
        ];

        foreach ($pairs as [$base, $target, $rate]) {
            $this->connection->executeStatement(
                'INSERT INTO fx_rates (id, base_currency, target_currency, rate, provider, fetched_at)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT (base_currency, target_currency, provider)
                 DO UPDATE SET rate = EXCLUDED.rate, fetched_at = EXCLUDED.fetched_at',
                [(string) FxRateId::generate(), $base, $target, $rate, 'seed', $now],
            );
        }
    }

    /**
     * Always upserts one well-known load test user so k6 and manual testing
     * have a stable login target regardless of how many times seed is run.
     *
     * Credentials: load_test@example.com / Password123!
     *
     * @return array{userIds: list<string>, accountIds: list<string>}
     */
    private function seedLoadTestUser(SymfonyStyle $io): array
    {
        $io->writeln('Ensuring load-test user (load_test@example.com)...');

        $userId    = (string) UserId::generate();
        $accountId = (string) AccountId::generate();
        $now       = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        // Upsert user — safe to call on every seed run
        $this->connection->executeStatement(
            'INSERT INTO users
                (id, email, first_name, last_name, password_hash, kyc_status, status, email_verified_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (email) DO NOTHING',
            [
                $userId,
                'load_test@example.com',
                'Load',
                'Test',
                password_hash('Password123!', PASSWORD_ARGON2ID),
                'verified',
                'active',
                $now,
                $now,
                $now,
            ]
        );

        // Fetch actual user ID in case the row already existed
        $existingId = $this->connection->fetchOne(
            'SELECT id FROM users WHERE email = ?',
            ['load_test@example.com']
        );

        $resolvedUserId = is_string($existingId) ? $existingId : $userId;

        // Query existing accounts by currency — insert only what is missing.
        // Avoids violating the unique (user_id, currency) partial index on re-seed.
        $existingByCurrency = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT id, currency FROM accounts WHERE user_id = ? AND status = ?',
            [$resolvedUserId, 'active']
        ) as $row) {
            /** @var array<string, string> $row */
            $existingByCurrency[$row['currency']] = $row['id'];
        }

        if (!isset($existingByCurrency['USD'])) {
            $this->connection->executeStatement(
                'INSERT INTO accounts (id, user_id, currency, balance, name, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$accountId, $resolvedUserId, 'USD', '10000.00000000', 'USD Account', 'active', $now, $now]
            );
            $existingByCurrency['USD'] = $accountId;
        }

        $toAccountId = (string) AccountId::generate();
        if (!isset($existingByCurrency['EUR'])) {
            $this->connection->executeStatement(
                'INSERT INTO accounts (id, user_id, currency, balance, name, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$toAccountId, $resolvedUserId, 'EUR', '10000.00000000', 'EUR Account', 'active', $now, $now]
            );
            $existingByCurrency['EUR'] = $toAccountId;
        }

        return [
            'userIds'    => [$resolvedUserId],
            'accountIds' => array_values($existingByCurrency),
        ];
    }

    /** @return list<string> */
    private function seedUsers(int $count, SymfonyStyle $io): array
    {
        $io->writeln("Seeding {$count} users...");
        $ids   = [];
        $batch = [];

        for ($i = 0; $i < $count; $i++) {
            $id    = (string) UserId::generate();
            $ids[] = $id;

            $batch[] = [
                'id'                => $id,
                'email'             => "seed_user_{$i}_" . substr($id, 0, 8) . '@example.com',
                'first_name'        => "First{$i}",
                'last_name'         => "Last{$i}",
                'password_hash'     => password_hash('Password123!', PASSWORD_ARGON2ID),
                'kyc_status'        => 'verified',
                'status'            => 'active',
                'email_verified_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'created_at'        => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'updated_at'        => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ];

            if (count($batch) >= 500) {
                $this->insertBatch('users', $batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->insertBatch('users', $batch);
        }

        return $ids;
    }

    /**
     * @param list<string> $userIds
     * @return list<string>
     */
    private function seedAccounts(int $perUser, array $userIds, SymfonyStyle $io): array
    {
        $io->writeln('Seeding accounts...');
        $ids        = [];
        $currencies = ['USD', 'EUR', 'GBP', 'CAD', 'AUD'];
        $batch      = [];

        foreach ($userIds as $userId) {
            $selected = array_slice($currencies, 0, min($perUser, count($currencies)));
            foreach ($selected as $currency) {
                $id    = (string) AccountId::generate();
                $ids[] = $id;

                $batch[] = [
                    'id'         => $id,
                    'user_id'    => $userId,
                    'currency'   => $currency,
                    'balance'    => number_format(random_int(10000, 1000000) / 100, 8, '.', ''),
                    'name'       => "{$currency} Account",
                    'status'     => 'active',
                    'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                ];

                if (count($batch) >= 500) {
                    $this->insertBatch('accounts', $batch);
                    $batch = [];
                }
            }
        }

        if ($batch !== []) {
            $this->insertBatch('accounts', $batch);
        }

        return $ids;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function insertBatch(string $table, array $rows, string $conflict = ''): void
    {
        if ($rows === []) {
            return;
        }

        $columns      = array_keys($rows[0]);
        $placeholders = [];
        $values       = [];

        foreach ($rows as $row) {
            $rowPlaceholders = array_fill(0, count($columns), '?');
            $placeholders[]  = '(' . implode(', ', $rowPlaceholders) . ')';
            foreach ($row as $value) {
                $values[] = $value;
            }
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s %s',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
            $conflict
        );

        $this->connection->executeStatement($sql, $values);
    }
}
