<?php

declare(strict_types=1);

namespace App\Domain\AuditLog;

use App\Domain\User\UserId;
use DateTimeImmutable;

/** Append-only — never update, never delete */
final readonly class AuditLog
{
    private function __construct(
        public AuditLogId $id,
        public string $entityType,
        public string $entityId,
        public string $action,
        public ?UserId $actorId,
        /** @var array<string, mixed>|null */
        public ?array $oldValue,
        /** @var array<string, mixed>|null */
        public ?array $newValue,
        public ?string $ipAddress,
        public ?string $userAgent,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed>|null $oldValue
     * @param array<string, mixed>|null $newValue
     */
    public static function record(
        AuditLogId $id,
        string $entityType,
        string $entityId,
        string $action,
        ?UserId $actorId = null,
        ?array $oldValue = null,
        ?array $newValue = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): self {
        return new self($id, $entityType, $entityId, $action, $actorId, $oldValue, $newValue, $ipAddress, $userAgent, new DateTimeImmutable());
    }
}
