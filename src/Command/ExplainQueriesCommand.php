<?php

declare(strict_types=1);

namespace App\Command;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:explain-queries', description: 'Run EXPLAIN on key queries and output as markdown')]
final class ExplainQueriesCommand extends Command
{
    private const PLACEHOLDER_UUID = '00000000-0000-4000-8000-000000000000';

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now  = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $uuid = self::PLACEHOLDER_UUID;

        /** @var list<array{label: string, sql: string, params: list<mixed>}> $queries */
        $queries = [
            [
                'label'  => 'accounts — lookup by primary key',
                'sql'    => 'SELECT * FROM accounts WHERE id = $1',
                'params' => [$uuid],
            ],
            [
                'label'  => 'accounts — list by user (with ordering)',
                'sql'    => 'SELECT * FROM accounts WHERE user_id = $1 ORDER BY created_at ASC',
                'params' => [$uuid],
            ],
            [
                'label'  => 'accounts — exists for user + currency',
                'sql'    => "SELECT COUNT(*) FROM accounts WHERE user_id = $1 AND currency = $2 AND status != $3",
                'params' => [$uuid, 'USD', 'closed'],
            ],
            [
                'label'  => 'users — lookup by primary key',
                'sql'    => 'SELECT * FROM users WHERE id = $1',
                'params' => [$uuid],
            ],
            [
                'label'  => 'users — lookup by email (login / uniqueness check)',
                'sql'    => 'SELECT * FROM users WHERE email = $1',
                'params' => ['user@example.com'],
            ],
            [
                'label'  => 'transfers — lookup by primary key',
                'sql'    => 'SELECT * FROM transfers WHERE id = $1',
                'params' => [$uuid],
            ],
            [
                'label'  => 'transfers — lookup by idempotency key',
                'sql'    => 'SELECT * FROM transfers WHERE idempotency_key = $1',
                'params' => ['idem-key-example'],
            ],
            [
                'label'  => 'transfers — paginated list for account (cursor-based)',
                'sql'    => 'SELECT * FROM transfers WHERE (from_account_id = $1 OR to_account_id = $2) AND created_at < $3 ORDER BY created_at DESC LIMIT $4',
                'params' => [$uuid, $uuid, $now, 21],
            ],
            [
                'label'  => 'transfers — duplicate detection within time window',
                'sql'    => "SELECT COUNT(*) FROM transfers WHERE from_account_id = $1 AND to_account_id = $2 AND amount = $3 AND currency = $4 AND status NOT IN ($5, $6) AND created_at > NOW() - INTERVAL '60 seconds'",
                'params' => [$uuid, $uuid, '100.00000000', 'USD', 'failed', 'completed'],
            ],
        ];

        $lines   = [];
        $lines[] = '# Query Execution Plans';
        $lines[] = '';
        $lines[] = sprintf('Generated: %s', $now);
        $lines[] = '';
        $lines[] = '> Plans produced with `EXPLAIN` (no data modified). Placeholder UUID: `' . $uuid . '`.';
        $lines[] = '';

        foreach ($queries as $entry) {
            $lines[] = sprintf('## %s', $entry['label']);
            $lines[] = '';
            $lines[] = '```sql';
            $lines[] = $entry['sql'];
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '```';

            $explainSql = 'EXPLAIN ' . preg_replace(
                '/\$\d+/',
                '?',
                $entry['sql'],
            );

            $rows = $this->connection->fetchAllAssociative($explainSql, $entry['params']);
            foreach ($rows as $row) {
                /** @var array<string, string> $row */
                $lines[] = implode('', array_values($row));
            }

            $lines[] = '```';
            $lines[] = '';
        }

        $output->write(implode("\n", $lines));

        return Command::SUCCESS;
    }
}
