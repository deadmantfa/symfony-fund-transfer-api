<?php

declare(strict_types=1);

namespace App\Domain\AuditLog;

use App\Domain\User\UserId;
use DateTimeImmutable;

/** Append-only — never update, never delete */
final readonly class AuditLog
{
    private function __construct(
        public readonly AuditLogId $id,
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly string $action,
        public readonly ?UserId $actorId,
        public readonly ?array $oldValue,
        public readonly ?array $newValue,
        public readonly ?string $ipAddress,
        public readonly ?string $userAgent,
        public readonly DateTimeImmutable $createdAt,
    ) {}

    public static function record(
        AuditLogId $id, string $entityType, string $entityId, string $action,
        ?UserId $actorId = null, ?array $oldValue = null, ?array $newValue = null,
        ?string $ipAddress = null, ?string $userAgent = null,
    ): self {
        return new self($id, $entityType, $entityId, $action, $actorId, $oldValue, $newValue, $ipAddress, $userAgent, new DateTimeImmutable());
    }
}
