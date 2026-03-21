<?php

declare(strict_types=1);

namespace App\Command;

use App\Domain\Account\AccountId;
use App\Domain\Transfer\TransferId;
use App\Domain\User\UserId;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

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
             ->addOption('accounts', null, InputOption::VALUE_REQUIRED, 'Accounts per user', 2)
             ->addOption('transfers', null, InputOption::VALUE_REQUIRED, 'Number of transfers', 100);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io           = new SymfonyStyle($input, $output);
        $usersOpt     = $input->getOption('users');
        $accountsOpt  = $input->getOption('accounts');
        $transfersOpt = $input->getOption('transfers');
        $users        = is_numeric($usersOpt) ? (int) $usersOpt : 10;
        $accounts     = is_numeric($accountsOpt) ? (int) $accountsOpt : 2;
        $transfers    = is_numeric($transfersOpt) ? (int) $transfersOpt : 100;

        $io->section("Seeding {$users} users, up to {$accounts} accounts/user, {$transfers} transfers");

        $this->connection->beginTransaction();

        try {
            $userIds    = $this->seedUsers($users, $io);
            $accountIds = $this->seedAccounts($accounts, $userIds, $io);
            $this->seedTransfers($transfers, $accountIds, $userIds, $io);

            $this->connection->commit();
            $io->success('Seeding complete.');
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
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
                'email_verified_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'created_at'        => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'updated_at'        => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
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
                    'balance'    => number_format(rand(10000, 1000000) / 100, 8, '.', ''),
                    'name'       => "{$currency} Account",
                    'status'     => 'active',
                    'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
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
     * @param list<string> $accountIds
     * @param list<string> $userIds
     */
    private function seedTransfers(int $count, array $accountIds, array $userIds, SymfonyStyle $io): void
    {
        if (count($accountIds) < 2) {
            $io->warning('Not enough accounts to seed transfers.');

            return;
        }

        $io->writeln("Seeding {$count} transfers...");
        $statuses = ['completed', 'completed', 'completed', 'failed'];
        $batch    = [];

        for ($i = 0; $i < $count; $i++) {
            $fromIdx = array_rand($accountIds);
            $toIdx   = array_rand($accountIds);
            if ($fromIdx === $toIdx) {
                continue;
            }

            $id      = (string) TransferId::generate();
            $amount  = number_format(rand(100, 10000) / 100, 8, '.', '');
            $daysAgo = rand(0, 365);
            $date    = (new \DateTimeImmutable("-{$daysAgo} days"))->format('Y-m-d H:i:s');
            $status  = $statuses[array_rand($statuses)];
            $userId  = $userIds[array_rand($userIds)];

            $fromAccountId = $accountIds[$fromIdx];
            $toAccountId   = $accountIds[$toIdx];

            $batch[] = [
                'id'                   => $id,
                'from_account_id'      => $fromAccountId,
                'to_account_id'        => $toAccountId,
                'amount'               => $amount,
                'currency'             => 'USD',
                'from_amount'          => $amount,
                'from_currency'        => 'USD',
                'to_amount'            => $amount,
                'to_currency'          => 'USD',
                'fx_rate'              => '1.00000000',
                'fx_rate_provider'     => 'stub',
                'status'               => $status,
                'idempotency_key'      => 'seed-' . $id,
                'failure_reason'       => null,
                'initiated_by_user_id' => $userId,
                'ip_address'           => '127.0.0.1',
                'user_agent'           => 'seed',
                'created_at'           => $date,
                'updated_at'           => $date,
            ];

            if (count($batch) >= 500) {
                $this->insertBatch('transfers', $batch, 'ON CONFLICT DO NOTHING');
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->insertBatch('transfers', $batch, 'ON CONFLICT DO NOTHING');
        }
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
