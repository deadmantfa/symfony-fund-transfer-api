<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\AuditLog\AuditLog;
use App\Domain\AuditLog\AuditLogId;
use App\Domain\AuditLog\AuditLogRepository;
use App\Domain\User\UserId;
use Doctrine\DBAL\Connection;

final readonly class DoctrineAuditLogRepository implements AuditLogRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function append(AuditLog $log): void
    {
        $this->connection->executeStatement(
            'INSERT INTO audit_logs
                (id, entity_type, entity_id, action, actor_id,
                 old_value, new_value, ip_address, user_agent, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [
                (string) $log->id,
                $log->entityType,
                $log->entityId,
                $log->action,
                $log->actorId instanceof UserId ? (string) $log->actorId : null,
                $log->oldValue !== null ? json_encode($log->oldValue) : null,
                $log->newValue !== null ? json_encode($log->newValue) : null,
                $log->ipAddress,
                $log->userAgent,
                $log->createdAt->format('Y-m-d H:i:s'),
            ]
        );
    }

    public function nextIdentity(): AuditLogId
    {
        return AuditLogId::generate();
    }
}
